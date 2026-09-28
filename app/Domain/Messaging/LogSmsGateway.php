<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Log;

/** Local development driver: writes messages to the log instead of sending them. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info("SMS to {$to}: {$message}");
    }
}
