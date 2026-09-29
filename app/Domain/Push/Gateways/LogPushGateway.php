<?php

namespace App\Domain\Push\Gateways;

use Illuminate\Support\Facades\Log;

/** No VAPID keys yet (local development): write what would be pushed to the log. */
class LogPushGateway implements PushGateway
{
    public function send(iterable $subscriptions, array $payload): array
    {
        foreach ($subscriptions as $subscription) {
            Log::info('Web push (log driver)', ['device' => $subscription->device, ...$payload]);
        }

        return [];
    }
}
