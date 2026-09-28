<?php

namespace App\Domain\Messaging;

use Illuminate\Support\Facades\Http;

/** WhatsApp Business Cloud API (Meta), template messages only. */
class MetaWhatsAppGateway implements WhatsAppGateway
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion = 'v21.0',
        private readonly string $language = 'en',
    ) {}

    public function send(string $to, Message $message): void
    {
        $text = fn (string $value) => ['type' => 'text', 'text' => $value];
        $components = [['type' => 'body', 'parameters' => array_map($text, $message->params)]];

        if ($message->authentication) {
            // Authentication templates repeat the code on their copy-code button.
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [$text($message->params[0])]];
        } elseif ($message->buttonSuffix !== null) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [$text($message->buttonSuffix)]];
        }

        Http::withToken($this->token)
            ->acceptJson()
            ->timeout(10)
            ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $message->template,
                    'language' => ['code' => $this->language],
                    'components' => $components,
                ],
            ])
            ->throw();
    }
}
