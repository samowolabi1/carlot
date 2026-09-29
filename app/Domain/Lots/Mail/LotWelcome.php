<?php

namespace App\Domain\Lots\Mail;

use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To a lot owner an admin signed up: the lot is ready and how to sign in. */
class LotWelcome extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lot $lot, public readonly string $ownerName, public readonly string $url)
    {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->lot->name} is set up on LotLink");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lot-welcome');
    }
}
