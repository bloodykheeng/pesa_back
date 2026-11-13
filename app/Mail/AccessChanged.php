<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An admin changed this account's role or status. `$changes`: ['role' => [old, new], 'status' => [old, new]]. */
class AccessChanged extends Mailable
{
    public function __construct(
        public User $user,
        public array $changes,
        public ?string $byName,
        public bool $staff,
    ) {}

    public function envelope(): Envelope
    {
        $inactive = ($this->changes['status'][1] ?? null) === 'inactive';

        return new Envelope(subject: $inactive ? 'Your Elevate Pesa account was made inactive' : 'Your Elevate Pesa access changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.access-changed');
    }
}
