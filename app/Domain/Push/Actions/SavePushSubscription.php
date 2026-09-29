<?php

namespace App\Domain\Push\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Push\Models\PushSubscription;

/**
 * Turns push on for this browser (or refreshes it: browsers rotate keys). An endpoint belongs to
 * one browser, so if someone else signs in on it, it moves to them.
 */
class SavePushSubscription
{
    /** @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null}  $subscription */
    public function run(User $user, array $subscription, ?string $userAgent = null): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hash($subscription['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $subscription['endpoint'],
                'public_key' => $subscription['keys']['p256dh'],
                'auth_token' => $subscription['keys']['auth'],
                'content_encoding' => in_array($subscription['contentEncoding'] ?? null, ['aesgcm', 'aes128gcm'], true) ? $subscription['contentEncoding'] : 'aes128gcm',
                'device' => self::device($userAgent),
            ],
        );
    }

    public function remove(string $endpoint): void
    {
        PushSubscription::query()->where('endpoint_hash', PushSubscription::hash($endpoint))->delete();
    }

    /** "Chrome on Android", from the user agent, so people can tell their devices apart. */
    public static function device(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }
}
