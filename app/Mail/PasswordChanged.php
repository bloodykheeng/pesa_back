<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A security notice: this account's password was changed, how and when. */
class PasswordChanged extends Mailable
{
    public function __construct(
        public User $user,
        public string $how,
        public ?string $byName,
        public bool $staff,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Elevate Pesa password was changed');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.password-changed');
    }
}
