<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Welcome to a new account, with a link to choose a password (never the password itself). */
class AccountCreated extends Mailable
{
    public function __construct(
        public User $user,
        public bool $staff,
        public ?string $byName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->staff ? 'Your Elevate Pesa admin account' : 'Welcome to Elevate Pesa');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account.created');
    }
}
