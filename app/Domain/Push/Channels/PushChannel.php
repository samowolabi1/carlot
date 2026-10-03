<?php

namespace App\Domain\Push\Channels;

use App\Domain\Accounts\Models\User;
use App\Domain\Push\Gateways\PushGateway;
use App\Domain\Push\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The `push` notification channel: a Web Push to every device the person turned it on for.
 * The message is the notification's `toPush()`, or else its notification-centre `toArray()`
 * (`kind`, `text`, `url`), so any notification can go by push. `NotificationPreferences::filter()`
 * adds this channel when the person has a device and hasn't turned that type off.
 */
class PushChannel
{
    /** Titles by notification kind; anything else is "CarYard". */
    public const TITLES = [
        'message' => 'New message',
        'booking' => 'Bookings',
        'lead' => 'New lead',
        'follow_up' => 'Follow-up due',
        'offer' => 'Offers',
        'billing' => 'Billing',
        'summary' => 'Daily summary',
        'review' => 'Reviews',
        'price_drop' => 'Price drop',
        'alert' => 'Saved search',
        'new_stock' => 'New cars',
        'location' => 'On the way',
        'finance' => 'Finance',
    ];

    public function __construct(private readonly PushGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()->get();
        $payload = $this->payload($notifiable, $notification);
        if ($subscriptions->isEmpty() || $payload === null) {
            return;
        }

        $gone = $this->gateway->send($subscriptions, $payload);

        if ($gone !== []) {
            PushSubscription::query()->whereIn('endpoint_hash', array_map(PushSubscription::hash(...), $gone))->delete();
        }
        PushSubscription::query()->whereKey($subscriptions->modelKeys())->whereNotIn('endpoint_hash', array_map(PushSubscription::hash(...), $gone))->update(['last_used_at' => now()]);
    }

    /** @return array{title: string, body: string, url: string|null, tag: string}|null */
    private function payload(User $user, Notification $notification): ?array
    {
        if (method_exists($notification, 'toPush')) {
            return $notification->toPush($user);
        }
        if (! method_exists($notification, 'toArray')) {
            return null;
        }

        /** @var array{kind?: string, text?: string, url?: string|null} $data */
        $data = $notification->toArray($user);
        $kind = (string) ($data['kind'] ?? 'info');

        return [
            'title' => self::TITLES[$kind] ?? 'CarYard',
            'body' => Str::limit((string) ($data['text'] ?? ''), 180),
            'url' => $data['url'] ?? null,
            // A newer push about the same thing replaces the older one on the phone.
            'tag' => $kind.'-'.substr(md5((string) ($data['url'] ?? $data['text'] ?? '')), 0, 10),
        ];
    }
}
