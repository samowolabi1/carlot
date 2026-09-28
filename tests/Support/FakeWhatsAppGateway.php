<?php

namespace Tests\Support;

use App\Domain\Messaging\Message;
use App\Domain\Messaging\WhatsAppGateway;
use RuntimeException;

class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array{to: string, message: Message}> */
    public array $sent = [];

    public bool $failing = false;

    public function send(string $to, Message $message): void
    {
        if ($this->failing) {
            throw new RuntimeException('WhatsApp is down');
        }

        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /** @return list<Message> */
    public function to(string $phone, ?string $template = null): array
    {
        return array_values(array_map(
            fn ($s) => $s['message'],
            array_filter($this->sent, fn ($s) => $s['to'] === $phone && ($template === null || $s['message']->template === $template)),
        ));
    }
}
