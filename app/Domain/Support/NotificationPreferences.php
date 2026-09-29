<?php

namespace App\Domain\Support;

use App\Domain\Accounts\Models\User;

/**
 * Per-user choices of how to hear about each kind of notification (TDD M18: Notification
 * centre). In-app is always on; WhatsApp/SMS ("phone") and email can be turned off.
 */
final class NotificationPreferences
{
    /** type => [label, who it's for] */
    public const TYPES = [
        'messages' => ['Chat messages', 'all'],
        'bookings' => ['Bookings and reminders', 'all'],
        'offers' => ['Offers, trade-ins and reservations', 'all'],
        'new_stock' => ['New cars from lots I follow', 'buyers'],
        'leads' => ['New leads and follow-ups', 'dealers'],
        'billing' => ['Billing and plan', 'dealers'],
        'summary' => ['Daily summary', 'dealers'],
        'reviews' => ['Reviews of visits', 'all'],
    ];

    public const OPTIONAL = ['phone', 'mail'];

    /**
     * The channels to use, with the user's opt-outs removed.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public static function filter(object $notifiable, string $type, array $channels): array
    {
        if (! $notifiable instanceof User) {
            return $channels;
        }

        $prefs = $notifiable->notification_preferences[$type] ?? [];

        return array_values(array_filter($channels, fn (string $channel) => ! in_array($channel, self::OPTIONAL, true) || ($prefs[$channel] ?? true)));
    }

    /** @return array<string, array{phone: bool, mail: bool}> */
    public static function for(User $user): array
    {
        $saved = $user->notification_preferences ?? [];

        return collect(self::TYPES)->mapWithKeys(fn ($meta, string $type) => [$type => [
            'phone' => (bool) ($saved[$type]['phone'] ?? true),
            'mail' => (bool) ($saved[$type]['mail'] ?? true),
        ]])->all();
    }
}
