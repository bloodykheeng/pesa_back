<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminList;
use App\Support\Audit\Audit;
use App\Support\Audit\Device;
use App\Support\DateBounds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The audit trail (/api/admin/audit-trail): who did what to which record, from where. Admins only.
 * Read-only: entries are written by App\Support\Audit\Audit and never edited.
 *
 * Filters: search, module, event, source (admin|old-admin|app|system), causer (user id),
 * subject_type (Order, Product...) + subject_id (one record's history), request (one request's
 * entries), from/to (Kampala days).
 */
class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $query = QueryBuilder::for(Activity::class)
            ->allowedFilters([
                AdminList::search(['description', 'properties']),
                AllowedFilter::exact('module', 'log_name'),
                AllowedFilter::exact('event'),
                // The audit trail's two tabs: changes to records, and sign-ins (with sign-outs and failed attempts).
                AllowedFilter::callback('kind', fn (Builder $q, $v) => match ((string) $v) {
                    'changes' => $q->whereIn('event', ['created', 'updated', 'deleted']),
                    'sign_ins' => $q->whereIn('event', Audit::SIGN_IN_EVENTS),
                    default => $q,
                }),
                AllowedFilter::exact('causer', 'causer_id'),
                AllowedFilter::exact('subject_id'),
                AllowedFilter::exact('request', 'batch_uuid'),
                AllowedFilter::callback('subject_type', fn (Builder $q, $v) => $q->where('subject_type', 'App\\Models\\'.class_basename((string) $v))),
                AllowedFilter::callback('source', fn (Builder $q, $v) => $q->where('properties', 'like', '%"source":"'.addcslashes((string) $v, '%_"\\').'"%')),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'created_at', 'event', 'log_name'])
            ->defaultSort('-id');

        $page = AdminList::paginate($query, $request);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Activity $a) => $this->row($a))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            // For the Module filter: the modules that have entries.
            'modules' => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $activity = Activity::findOrFail($id);
        $properties = $activity->properties?->toArray() ?? [];

        return response()->json(['data' => [
            ...$this->row($activity),
            'snapshot' => $properties['snapshot'] ?? null,
            'changes' => $properties['changes'] ?? null,
            'request' => isset($properties['request']) ? [...$properties['request'], 'device' => $this->device($properties)] : null,
            'extra' => $properties['extra'] ?? null,
            // How many entries the same request wrote (an order and its payment, say).
            'same_request' => $activity->batch_uuid ? Activity::where('batch_uuid', $activity->batch_uuid)->count() : 1,
        ]]);
    }

    private function row(Activity $a): array
    {
        $p = $a->properties?->toArray() ?? [];

        return [
            'id' => $a->id,
            'created_at' => $a->created_at?->toIso8601ZuluString(),
            'event' => $a->event,
            'event_label' => Audit::EVENT_LABELS[$a->event] ?? $a->event,
            'module' => $a->log_name,
            'description' => $a->description,
            // A sign-in's "record" is the sentence itself ("Signed in to the app"); its person is the Who.
            'title' => in_array($a->event, ['created', 'updated', 'deleted'], true) ? ($p['title'] ?? null) : $a->description,
            'subject_type' => $a->subject_type ? class_basename($a->subject_type) : null,
            'subject_id' => $a->subject_id,
            'causer_id' => $a->causer_id,
            'causer' => $p['causer'] ?? null,
            // The address typed on a failed sign-in or a reset request (nobody is signed in then).
            'login' => $p['extra']['login'] ?? null,
            'outcome' => $p['extra']['outcome'] ?? null,
            'source' => $p['source'] ?? null,
            'request_id' => $a->batch_uuid,
            'changed' => isset($p['changes']) ? count($p['changes']) : null,
            // The first few changes as one line each, so the list says what changed without opening it.
            'changes_preview' => array_map(fn (array $c) => [
                'label' => $c['label'] ?? $c['field'] ?? '',
                'old' => $this->plain($c['old'] ?? null, $c['type'] ?? null),
                'new' => $this->plain($c['new'] ?? null, $c['type'] ?? null),
                'secret' => ($c['type'] ?? null) === 'secret',
            ], array_slice($p['changes'] ?? [], 0, 3)),
            'ip' => $p['request']['ip'] ?? null,
            'device' => isset($p['request']) ? $this->device($p)['summary'] : null,
        ];
    }

    /** @return array{kind: string, platform: ?string, browser: ?string, summary: string} */
    private function device(array $p): array
    {
        $stored = $p['request']['device'] ?? null;

        return is_array($stored) ? $stored : Device::describe($p['request']['user_agent'] ?? null);
    }

    /** A frozen value as short text: {id, label} and {value, label} by their label, lists by their size. */
    private function plain(mixed $value, ?string $type): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            if (array_key_exists('label', $value)) {
                return (string) $value['label'];
            }

            return count($value).' '.(count($value) === 1 ? 'item' : 'items');
        }

        return match ($type) {
            'boolean' => $value ? 'Yes' : 'No',
            'money' => 'UGX '.number_format((float) $value),
            // A snapshot's date is already a Kampala day ("2026-11-09"); a datetime is UTC, shown on the business clock.
            'date' => Carbon::parse((string) DateBounds::businessDayOf($value))->format('j M Y'),
            'datetime' => Carbon::parse((string) $value, 'UTC')->setTimezone(DateBounds::timezone())->format('j M Y, g:i a'),
            default => Str::limit((string) $value, 60),
        };
    }
}
