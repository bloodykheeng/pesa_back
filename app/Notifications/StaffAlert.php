<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A line in the staff bell (top bar of the dashboard): a new order, a package booking, a sign-up,
 * a message send that finished. `link` is the dashboard page that opens it.
 */
class StaffAlert extends Notification
{
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public ?string $link = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link];
    }

    /** Every staff account gets it, except the one who caused it (`$except`), who knows already. */
    public static function toStaff(string $kind, string $title, string $body, ?string $link = null, ?int $except = null): void
    {
        $staff = \App\Models\User::role(\App\Models\User::STAFF_ROLES)->when($except, fn ($q) => $q->whereKeyNot($except))->get();
        \Illuminate\Support\Facades\Notification::send($staff, new self($kind, $title, $body, $link));
    }
}
