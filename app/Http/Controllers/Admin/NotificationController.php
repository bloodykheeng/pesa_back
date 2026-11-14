<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Jobs\SendAdminNotification;
use App\Models\AdminNotification;
use App\Models\AdminNotificationRecipient;
use App\Models\User;
use App\Support\AdminList;
use App\Support\DateBounds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Messages from staff to customers: to everyone or to picked customers, as a push to the app,
 * an email, or both. Sent in the background (queue); the history shows who got what.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = QueryBuilder::for(AdminNotification::class)
            ->with('createdBy:id,name')
            ->allowedFilters([
                AdminList::search(['title', 'body']),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('audience'),
                ...AdminList::dateRange(),
            ])
            ->allowedSorts(['id', 'title', 'recipients_count', 'created_at'])
            ->defaultSort('-created_at');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    public function show(int $id)
    {
        return new AdminResource(AdminNotification::with('createdBy:id,name')->findOrFail($id));
    }

    /** GET /api/admin/notifications/{id}/recipients */
    public function recipients(Request $request, int $id)
    {
        AdminNotification::findOrFail($id);

        $query = QueryBuilder::for(AdminNotificationRecipient::where('admin_notification_id', $id))
            ->with('user:id,name,email,phone')
            ->allowedFilters([
                AdminList::search(['user.name', 'user.email', 'user.phone']),
                AllowedFilter::exact('push_status'),
                AllowedFilter::exact('email_status'),
            ])
            ->allowedSorts(['id', 'push_status', 'email_status'])
            ->defaultSort('id');

        return AdminResource::collection(AdminList::paginate($query, $request));
    }

    /**
     * How many customers a send would reach on each channel, for the form's summary line, and how
     * many emails are left today.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => 'required|in:all_customers,selected',
            'user_ids' => 'required_if:audience,selected|array',
            'user_ids.*' => 'integer',
        ]);
        $users = $this->audience($data);

        return response()->json([
            'recipients' => (clone $users)->count(),
            'with_device' => (clone $users)->whereNotNull('device_token')->where('device_token', '!=', '')->count(),
            'with_email' => (clone $users)->whereNotNull('email')->where('email', '!=', '')->count(),
            'emails_left_today' => $this->emailsLeftToday(),
            'daily_email_limit' => config('app.mail_daily_limit'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:1000',
            'send_push' => 'required|boolean',
            'send_email' => 'required|boolean',
            'audience' => 'required|in:all_customers,selected',
            'user_ids' => 'required_if:audience,selected|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ], [
            'user_ids.required_if' => 'Pick at least one customer.',
        ]);

        if (! $data['send_push'] && ! $data['send_email']) {
            return response()->json(['message' => 'Choose push, email or both.', 'errors' => ['send_push' => ['Choose push, email or both.']]], 422);
        }

        $users = $this->audience($data)->get(['id', 'email', 'device_token']);
        if ($users->isEmpty()) {
            return response()->json(['message' => 'Nobody to send to.'], 422);
        }

        if ($data['send_email']) {
            $emails = $users->filter(fn ($u) => ! empty($u->email))->count();
            $left = $this->emailsLeftToday();
            if ($emails > $left) {
                return response()->json([
                    'message' => "That is {$emails} emails, but only {$left} are left today (the mail plan allows " . config('app.mail_daily_limit') . ' a day). Send as push only, or pick fewer customers.',
                    'errors' => ['send_email' => ["Only {$left} emails left today."]],
                ], 422);
            }
        }

        $notification = DB::transaction(function () use ($data, $users, $request) {
            $notification = AdminNotification::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'send_push' => $data['send_push'],
                'send_email' => $data['send_email'],
                'audience' => $data['audience'],
                'recipients_count' => $users->count(),
                'created_by' => $request->user()->id,
            ]);

            $now = now();
            $users->chunk(500)->each(fn ($chunk) => AdminNotificationRecipient::insert($chunk->map(fn ($u) => [
                'admin_notification_id' => $notification->id,
                'user_id' => $u->id,
                'push_status' => $data['send_push'] ? (empty($u->device_token) ? 'skipped' : 'pending') : null,
                'email_status' => $data['send_email'] ? (empty($u->email) ? 'skipped' : 'pending') : null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()));

            return $notification;
        });

        SendAdminNotification::dispatch($notification->id)->afterCommit();

        return response()->json([
            'message' => 'Sending to ' . $users->count() . ' customer' . ($users->count() === 1 ? '' : 's') . '. The bell tells you when it is done.',
            'data' => new AdminResource($notification),
        ], 202);
    }

    private function audience(array $data)
    {
        $query = User::role('Customer');

        return $data['audience'] === 'selected'
            ? User::whereIn('id', $data['user_ids'] ?? [])
            : $query;
    }

    private function emailsLeftToday(): int
    {
        $start = DateBounds::startInstant(DateBounds::today());
        $used = AdminNotificationRecipient::whereIn('email_status', ['pending', 'sent'])->where('updated_at', '>=', $start)->count();

        return max(0, (int) config('app.mail_daily_limit') - $used);
    }
}
