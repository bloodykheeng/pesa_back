<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A Blade email sent by the queue worker: the app-era `Mail::send(view, data, closure)` calls, which cannot be queued
 * (a closure does not serialize), become `Mail::to($address)->send(new ViewMail(view, data, subject))`. Models in
 * `data` are stored by id and loaded fresh when it is sent.
 */
class ViewMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * Protected, not public: a mailable's public properties are handed to the template and would overwrite its own
     * variables (the reset email's `$data`).
     *
     * @param  array<string, mixed>  $data
     */
    public function __construct(protected string $template, protected array $data, protected string $title) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: $this->template, with: $this->data);
    }
}
