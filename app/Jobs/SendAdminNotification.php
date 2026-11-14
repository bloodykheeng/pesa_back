<?php

namespace App\Jobs;

use App\Events\StaffSignal;
use App\Models\AdminNotification;
use App\Models\AdminNotificationRecipient;
use App\Notifications\StaffAlert;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one AdminNotification to its pending recipients: push through Firebase to the app, email
 * through the mailer (MailDog). Each recipient's result is saved as it goes, so a restart picks up
 * where it stopped and the history shows exactly who got what.
 */
class SendAdminNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $notification = AdminNotification::findOrFail($this->notificationId);
        $notification->update(['status' => 'sending']);

        $firebase = null;
        $firebaseError = null;
        if ($notification->send_push) {
            try {
                $firebase = app(FirebaseService::class);
            } catch (Throwable $e) {
                $firebaseError = Str::limit('Push service unavailable: ' . $e->getMessage(), 480);
            }
        }

        AdminNotificationRecipient::with('user:id,name,email,device_token')
            ->where('admin_notification_id', $notification->id)
            ->where(fn ($q) => $q->where('push_status', 'pending')->orWhere('email_status', 'pending'))
            ->chunkById(100, function ($recipients) use ($notification, $firebase, $firebaseError) {
                foreach ($recipients as $recipient) {
                    $this->deliver($notification, $recipient, $firebase, $firebaseError);
                }
                // The admin's Messages page follows the send live (docs/realtime.md).
                StaffSignal::fire('messages', ['id' => $notification->id]);
            });

        $this->finish($notification);
        StaffSignal::fire('messages', ['id' => $notification->id]);
    }

    private function deliver(AdminNotification $notification, AdminNotificationRecipient $recipient, ?FirebaseService $firebase, ?string $firebaseError): void
    {
        $errors = [];
        $user = $recipient->user;

        if ($recipient->push_status === 'pending') {
            if (! $firebase) {
                $recipient->push_status = 'failed';
                $errors[] = $firebaseError ?? 'Push service unavailable.';
            } else {
                try {
                    $firebase->sendNotification($user->device_token, $notification->title, $notification->body, ['kind' => 'admin_message']);
                    $recipient->push_status = 'sent';
                } catch (Throwable $e) {
                    $recipient->push_status = 'failed';
                    $errors[] = 'Push: ' . Str::limit($e->getMessage(), 200);
                }
            }
        }

        if ($recipient->email_status === 'pending') {
            try {
                Mail::send('emails.generalNotification', ['notificationMessage' => $notification->body], function ($message) use ($user, $notification) {
                    $message->to($user->email, $user->name)->subject($notification->title);
                });
                $recipient->email_status = 'sent';
            } catch (Throwable $e) {
                $recipient->email_status = 'failed';
                $errors[] = 'Email: ' . Str::limit($e->getMessage(), 200);
            }
        }

        $recipient->error = $errors ? Str::limit(implode(' | ', $errors), 490) : null;
        $recipient->save();
    }

    private function finish(AdminNotification $notification): void
    {
        $count = fn (string $column, string $status) => AdminNotificationRecipient::where('admin_notification_id', $notification->id)->where($column, $status)->count();

        $notification->update([
            'push_sent' => $count('push_status', 'sent'),
            'push_failed' => $count('push_status', 'failed'),
            'email_sent' => $count('email_status', 'sent'),
            'email_failed' => $count('email_status', 'failed'),
            'finished_at' => now(),
        ]);
        $delivered = $notification->push_sent + $notification->email_sent;
        $notification->update(['status' => $delivered === 0 && ($notification->push_failed + $notification->email_failed) > 0 ? 'failed' : 'sent']);

        $parts = [];
        if ($notification->send_push) {
            $parts[] = $notification->push_sent . ' to phones' . ($notification->push_failed ? ' (' . $notification->push_failed . ' failed)' : '');
        }
        if ($notification->send_email) {
            $parts[] = $notification->email_sent . ' by email' . ($notification->email_failed ? ' (' . $notification->email_failed . ' failed)' : '');
        }

        $notification->createdBy?->notify(new StaffAlert(
            'send', '"' . Str::limit($notification->title, 40) . '" sent', implode(', ', $parts) . '.',
            '/dashboard/messages?view=' . $notification->id,
        ));
    }

    public function failed(Throwable $e): void
    {
        AdminNotification::whereKey($this->notificationId)->update(['status' => 'failed', 'finished_at' => now()]);
        StaffSignal::fire('messages', ['id' => $this->notificationId]);
    }
}
