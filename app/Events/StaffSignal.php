<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Something changed, refresh it": a small live signal to the admin through Reverb, so the admin never polls
 * (docs/realtime.md). It carries a topic and a few ids, never the records themselves; the admin refetches what the
 * topic names through the normal, permission-checked API.
 *
 *   inbox     (one staff member's channel) a bell alert arrived: refresh the bell, which toasts the new one
 *   records   (staff channel) an order, package or customer was created: refresh those lists and the dashboard
 *   queues    (staff channel) a job was queued, finished or failed: refresh Settings > Queues
 *   messages  (staff channel) a message to customers is sending or finished: refresh Messages
 *
 * Sent now, never through the queue: a queued broadcast would itself be a job and signal "queues" again, and the
 * Queues page would loop. fire() never throws: Reverb being down must not break an order, a payment or a send.
 */
class StaffSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $data */
    public function __construct(public string $topic, public array $data = [], public ?int $userId = null) {}

    /** @param  array<string, mixed>  $data */
    public static function fire(string $topic, array $data = [], ?int $userId = null): void
    {
        try {
            event(new self($topic, $data, $userId));
        } catch (Throwable $e) {
            Log::warning('Live signal "'.$topic.'" not sent: '.$e->getMessage());
        }
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->userId ? 'App.Models.User.'.$this->userId : 'staff')];
    }

    public function broadcastAs(): string
    {
        return 'signal';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['topic' => $this->topic, ...$this->data];
    }
}
