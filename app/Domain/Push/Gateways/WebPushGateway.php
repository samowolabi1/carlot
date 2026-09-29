<?php

namespace App\Domain\Push\Gateways;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/** Web Push with VAPID keys (`php artisan push:vapid`), through each browser's push service. */
class WebPushGateway implements PushGateway
{
    /** @param  ClientInterface|null  $client  HTTP client (tests pass one that fakes the push service) */
    public function __construct(
        private readonly string $subject,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly ?ClientInterface $client = null,
    ) {}

    public function send(iterable $subscriptions, array $payload): array
    {
        // The logger turns the library's "install GMP or BCMath for speed" notice into a log line
        // (otherwise Laravel makes it an exception and no push goes out on a server without them).
        $push = new WebPush(
            ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]],
            ['TTL' => 86400, 'urgency' => 'normal'],
            $this->client,
            logger: Log::channel(),
        );
        $push->setReuseVAPIDHeaders(true);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), (string) json_encode($payload));
        }

        $gone = [];
        foreach ($push->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $gone[] = $report->getEndpoint();
            } elseif (! $report->isSuccess()) {
                Log::warning('Web push failed', ['reason' => $report->getReason()]);
            }
        }

        return $gone;
    }
}
