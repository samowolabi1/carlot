<?php

namespace App\Domain\Push\Gateways;

use App\Domain\Push\Models\PushSubscription;

/** Sends Web Push messages (webpush with VAPID keys, or log). Tests bind a fake. */
interface PushGateway
{
    /**
     * @param  iterable<PushSubscription>  $subscriptions
     * @param  array{title: string, body: string, url: string|null, tag: string}  $payload
     * @return list<string> endpoints the push service says are gone (the browser unsubscribed), to delete
     */
    public function send(iterable $subscriptions, array $payload): array;
}
