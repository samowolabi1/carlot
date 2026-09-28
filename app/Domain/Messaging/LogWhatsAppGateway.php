<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Log;

/** Local development driver: writes WhatsApp messages to the log. */
class LogWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $to, Message $message): void
    {
        Log::info("WhatsApp to {$to} [{$message->template}]: {$message->text}");
    }
}
