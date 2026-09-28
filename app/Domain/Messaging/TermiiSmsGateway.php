<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Http;

class TermiiSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly string $baseUrl,
    ) {}

    public function send(string $to, string $message): void
    {
        Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(10)
            ->post('/api/sms/send', [
                'api_key' => $this->apiKey,
                'to' => ltrim($to, '+'),
                'from' => $this->senderId,
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'dnd',
            ])
            ->throw();
    }
}
