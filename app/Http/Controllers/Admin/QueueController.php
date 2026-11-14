<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Support\AdminList;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Background work (Settings › Queues, Admins only), as Alpha and NICE show theirs: jobs waiting in the `jobs` table,
 * and jobs that failed (`failed_jobs`), with retry and delete. Today one job runs on the queue: SendAdminNotification
 * (Messages to customers), which only sends to recipients still pending, so a retry never sends twice.
 */
class QueueController extends Controller
{
    /** Waiting longer than this with nothing running: no worker is picking jobs up. */
    private const STUCK_AFTER_SECONDS = 120;

    /** GET /api/admin/queues/stats */
    public function stats(): JsonResponse
    {
        $now = time();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');
        $running = DB::table('jobs')->whereNotNull('reserved_at')->count();

        return response()->json([
            'waiting' => DB::table('jobs')->whereNull('reserved_at')->count(),
            'running' => $running,
            'failed' => DB::table('failed_jobs')->count(),
            'oldest_waiting_since' => $oldest ? CarbonImmutable::createFromTimestampUTC((int) $oldest)->toJSON() : null,
            'worker_missing' => $oldest !== null && $running === 0 && $now - (int) $oldest > self::STUCK_AFTER_SECONDS,
        ]);
    }

    /** GET /api/admin/queues/jobs: waiting, delayed and running jobs, newest first. */
    public function jobs(Request $request)
    {
        $query = DB::table('jobs')
            ->when($request->input('filter.search'), fn ($q, $s) => $q->where('payload', 'like', '%'.$s.'%'))
            ->when($request->input('filter.queue'), fn ($q, $v) => $q->where('queue', $v))
            ->orderBy('id', $request->input('sort') === 'id' ? 'asc' : 'desc');

        $page = AdminList::paginate($query, $request)->through(fn ($job) => [
            'id' => $job->id,
            'queue' => $job->queue,
            'attempts' => $job->attempts,
            'state' => $job->reserved_at ? 'running' : ($job->available_at > time() ? 'delayed' : 'waiting'),
            'created_at' => CarbonImmutable::createFromTimestampUTC($job->created_at)->toJSON(),
            'available_at' => CarbonImmutable::createFromTimestampUTC($job->available_at)->toJSON(),
            'reserved_at' => $job->reserved_at ? CarbonImmutable::createFromTimestampUTC($job->reserved_at)->toJSON() : null,
            ...self::describe($job->payload),
        ]);

        return AdminResource::collection($page);
    }

    /** GET /api/admin/queues/failed */
    public function failed(Request $request)
    {
        $query = DB::table('failed_jobs')
            ->when($request->input('filter.search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('payload', 'like', '%'.$s.'%')->orWhere('exception', 'like', '%'.$s.'%')))
            ->when($request->input('filter.queue'), fn ($q, $v) => $q->where('queue', $v))
            ->orderBy('id', $request->input('sort') === 'id' ? 'asc' : 'desc');

        $page = AdminList::paginate($query, $request)->through(fn ($job) => [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'failed_at' => CarbonImmutable::parse($job->failed_at, 'UTC')->toJSON(),
            'error' => Str::limit(strtok((string) $job->exception, "\n"), 300),
            ...self::describe($job->payload),
        ]);

        return AdminResource::collection($page);
    }

    /** GET /api/admin/queues/failed/{id}: the whole error, for the details. */
    public function failedOne(int $id): JsonResponse
    {
        $job = DB::table('failed_jobs')->find($id) ?? abort(404, 'That failed job is no longer there.');

        return response()->json(['data' => [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'failed_at' => CarbonImmutable::parse($job->failed_at, 'UTC')->toJSON(),
            'error' => Str::limit(strtok((string) $job->exception, "\n"), 300),
            'exception' => Str::limit((string) $job->exception, 20000),
            ...self::describe($job->payload),
        ]]);
    }

    /** POST /api/admin/queues/failed/retry {ids}: back onto the queue (`queue:retry`). */
    public function retry(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer'])['ids'];
        $jobs = DB::table('failed_jobs')->whereIn('id', $ids)->get(['uuid', 'payload']);
        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'Those failed jobs are no longer there.'], 422);
        }

        Artisan::call('queue:retry', ['id' => $jobs->pluck('uuid')->all()]);
        Audit::event('jobs_retried', null, $jobs->count().' failed '.Str::plural('job', $jobs->count()).' put back on the queue', [
            'jobs' => $jobs->map(fn ($j) => self::describe($j->payload)['job'])->all(),
        ], $request->user(), 'Queues');

        return response()->json(['message' => $jobs->count().' '.Str::plural('job', $jobs->count()).' put back on the queue.']);
    }

    /** POST /api/admin/queues/jobs/bulk-delete {ids}: drop waiting jobs (they will never run). */
    public function destroyJobs(Request $request): JsonResponse
    {
        return $this->destroy($request, 'jobs', 'waiting');
    }

    /** POST /api/admin/queues/failed/bulk-delete {ids} */
    public function destroyFailed(Request $request): JsonResponse
    {
        return $this->destroy($request, 'failed_jobs', 'failed');
    }

    private function destroy(Request $request, string $table, string $kind): JsonResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer'])['ids'];
        $jobs = DB::table($table)->whereIn('id', $ids)->get(['id', 'payload']);
        DB::table($table)->whereIn('id', $jobs->pluck('id'))->delete();
        $count = $jobs->count();

        if ($count) {
            Audit::event('jobs_deleted', null, $count.' '.$kind.' '.Str::plural('job', $count).' deleted', [
                'jobs' => $jobs->map(fn ($j) => self::describe($j->payload)['job'])->all(),
            ], $request->user(), 'Queues');
        }

        return response()->json(['message' => $count.' '.$kind.' '.Str::plural('job', $count).' deleted.']);
    }

    /**
     * What a job is, in words, from its payload: its class as a name ("Send admin notification"), and for a message to
     * customers which message (and a link to it). Read with a pattern, never unserialized.
     *
     * @return array{job: string, class: string, about: ?string, link: ?string}
     */
    public static function describe(string $payload): array
    {
        $data = json_decode($payload, true) ?: [];
        $class = (string) ($data['displayName'] ?? $data['job'] ?? 'Unknown');
        $out = ['job' => Str::ucfirst(Str::lower(Str::headline(class_basename($class)))), 'class' => $class, 'about' => null, 'link' => null];

        $command = (string) ($data['data']['command'] ?? '');
        // A string property out of the serialized job (public, or protected with its \0*\0 prefix): a push's or an email's title.
        $text = fn (string $name) => preg_match('/'.$name.'";s:\d+:"([^"]*)"/', $command, $m) ? Str::limit($m[1], 80) : null;

        if ($class === \App\Jobs\SendPush::class) {
            $out['job'] = 'Push message to a phone';
            $out['about'] = $text('title') ? '"'.$text('title').'"' : null;
        } elseif ($class === \Illuminate\Mail\SendQueuedMailable::class) {
            $mailable = preg_match('/O:\d+:"(App\\\\Mail\\\\\w+)"/', $command, $m) ? class_basename($m[1]) : null;
            $subjects = ['AccountCreated' => 'Welcome to a new account', 'PasswordChanged' => 'Password changed', 'AccessChanged' => 'Access changed'];
            $out['job'] = 'Email';
            $out['about'] = $mailable === 'ViewMail' ? ($text('title') ? '"'.$text('title').'"' : null) : ($subjects[$mailable] ?? $mailable);
        }

        if ($class === \App\Jobs\SendAdminNotification::class) {
            $out['job'] = 'Send a message to customers';
            if (preg_match('/notificationId";i:(\d+)/', (string) ($data['data']['command'] ?? ''), $m)) {
                $title = DB::table('admin_notifications')->where('id', $m[1])->value('title');
                $out['about'] = $title ? '"'.Str::limit($title, 80).'"' : 'Message #'.$m[1].' (deleted)';
                $out['link'] = '/dashboard/messages?view='.$m[1];
            }
        }

        return $out;
    }
}
