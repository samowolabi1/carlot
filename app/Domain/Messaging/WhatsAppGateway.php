<?php

namespace App\Domain\Messaging;

interface WhatsAppGateway
{
    /** @throws \Throwable when the message could not be sent (callers fall back to SMS) */
    public function send(string $to, Message $message): void;
}
