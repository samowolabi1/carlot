<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one message to a phone: WhatsApp first (most Nigerian buyers and dealers live
 * there), SMS if WhatsApp fails or isn't wanted.
 */
class Messenger
{
    public function __construct(
        private readonly WhatsAppGateway $whatsApp,
        private readonly SmsGateway $sms,
    ) {}

    /** @return 'whatsapp'|'sms' the channel that delivered it */
    public function send(string $to, Message $message, bool $preferWhatsApp = true): string
    {
        if ($preferWhatsApp) {
            try {
                $this->whatsApp->send($to, $message);

                return 'whatsapp';
            } catch (Throwable $e) {
                Log::warning("WhatsApp to {$to} failed, sending SMS instead: {$e->getMessage()}");
            }
        }

        $this->sms->send($to, $message->text);

        return 'sms';
    }
}
