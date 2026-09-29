<?php

namespace App\Domain\Accounts\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The sign-in code by email (sent straight away, not queued: the person is waiting for it). */
class LoginCode extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your LotLink code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.login-code');
    }
}
