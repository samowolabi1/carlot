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

    /** @return 'whatsapp'|'sms'|'off' the channel that delivered it ('off' when an admin switched the message off) */
    public function send(string $to, Message $message, bool $preferWhatsApp = true): string
    {
        $message = MessageCatalogue::apply($message);

        if ($message === null) {
            return 'off';
        }

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
