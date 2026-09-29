<?php

namespace Tests\Support;

use App\Domain\Push\Gateways\PushGateway;

class FakePushGateway implements PushGateway
{
    /** @var list<array{device: string|null, endpoint: string, payload: array{title: string, body: string, url: string|null, tag: string}}> */
    public array $sent = [];

    /** @var list<string> endpoints to report as gone on the next send */
    public array $gone = [];

    public function send(iterable $subscriptions, array $payload): array
    {
        foreach ($subscriptions as $subscription) {
            $this->sent[] = ['device' => $subscription->device, 'endpoint' => $subscription->endpoint, 'payload' => $payload];
        }

        [$gone, $this->gone] = [$this->gone, []];

        return $gone;
    }

    /** @return list<array{title: string, body: string, url: string|null, tag: string}> */
    public function to(string $endpoint): array
    {
        return array_values(array_map(fn ($s) => $s['payload'], array_filter($this->sent, fn ($s) => $s['endpoint'] === $endpoint)));
    }
}
