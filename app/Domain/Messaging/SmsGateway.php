<?php

namespace App\Domain\Messaging;

interface SmsGateway
{
    /** Send a plain text message to an E.164 number. */
    public function send(string $to, string $message): void;
}
