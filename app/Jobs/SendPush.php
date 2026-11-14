<?php

namespace App\Jobs;

use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;

/**
 * One push message to one phone, sent by the queue worker so the request that caused it (an order placed, a payment
 * recorded, a delivery changed) answers at once instead of waiting on Firebase. Push::send() dispatches it.
 *
 * A phone that uninstalled the app (NotFound) or a bad token (InvalidMessage) is logged and dropped: retrying cannot
 * help. Anything else (Firebase slow or down) is retried, then lands in Settings › Queues › Failed.
 */
class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> seconds before the 2nd and 3rd tries */
    public array $backoff = [30, 120];

    /**
     * A null token sends to every phone with the app (Firebase's "elevatePesa" topic: an advert going live).
     *
     * @param  array<string, string>  $data
     */
    public function __construct(public ?string $token, public string $title, public string $body, public array $data = []) {}

    public function handle(FirebaseService $firebase): void
    {
        try {
            $this->token
                ? $firebase->sendNotification($this->token, $this->title, $this->body, $this->data)
                : $firebase->sendNotificationTopic($this->title, $this->body, $this->data);
        } catch (NotFound|InvalidMessage $e) {
            Log::info('Push dropped, the phone is no longer reachable: '.$e->getMessage());
        }
    }
}
