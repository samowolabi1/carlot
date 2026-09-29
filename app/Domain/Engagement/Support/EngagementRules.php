<?php

namespace App\Domain\Engagement\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The automated emails to lot owners, with their defaults. Admins switch each on or off and change
 * when it goes (the threshold), how often (cooldown days), the subject and the opening line in
 * /admin → Settings → Automated emails; those overrides live in `platform_settings`.
 * Placeholders: {name} (owner's first name), {lot}, {count}.
 */
final class EngagementRules
{
    public const KEY = 'engagement_rules';

    private const CACHE = 'lotlink.settings.engagement_rules';

    /**
     * @var array<string, array{label: string, description: string, unit: 'days'|'hours', threshold: int, cooldown: int, subject: string, intro: string, cta: string}>
     */
    public const RULES = [
        'inactive_owner' => [
            'label' => 'Owner hasn\'t signed in',
            'description' => 'The lot is live but its owner hasn\'t used LotLink for a while. Includes the lot\'s views, leads and waiting chats.',
            'unit' => 'days', 'threshold' => 30, 'cooldown' => 30,
            'subject' => 'Buyers are still looking at {lot}',
            'intro' => 'It\'s been a while since you signed in to LotLink. Here\'s what has happened at {lot} in the last 30 days:',
            'cta' => 'Open your dashboard',
        ],
        'no_cars' => [
            'label' => 'No cars uploaded',
            'description' => 'The lot is approved but has never added a car.',
            'unit' => 'days', 'threshold' => 3, 'cooldown' => 7,
            'subject' => 'Add your first cars to {lot}',
            'intro' => 'Buyers can\'t find {lot} until it has cars. Adding one takes about two minutes: photos, price, done. Lots with 10 or more cars get most of the enquiries.',
            'cta' => 'Add a car',
        ],
        'drafts_waiting' => [
            'label' => 'Cars left as drafts',
            'description' => 'Cars saved as drafts and not touched for a while, so buyers can\'t see them.',
            'unit' => 'days', 'threshold' => 3, 'cooldown' => 7,
            'subject' => 'Cars at {lot} aren\'t live yet',
            'intro' => 'These cars are saved as drafts, so buyers can\'t see them. Add the missing details and publish them:',
            'cta' => 'Finish your cars',
        ],
        'setup_incomplete' => [
            'label' => 'Setup not finished',
            'description' => 'The lot was created but never submitted for approval.',
            'unit' => 'days', 'threshold' => 2, 'cooldown' => 5,
            'subject' => 'Finish setting up {lot}',
            'intro' => 'You\'re nearly there. Add your location and opening hours, then submit {lot} for approval so buyers can find you.',
            'cta' => 'Finish setup',
        ],
        'pending_actions' => [
            'label' => 'Buyers waiting (daily digest)',
            'description' => 'Unanswered chats, bookings to confirm, offers, reservations and trade-ins waiting on the lot.',
            'unit' => 'hours', 'threshold' => 4, 'cooldown' => 1,
            'subject' => 'Buyers are waiting at {lot}',
            'intro' => 'Buyers are waiting for {lot} to answer. Quick replies win sales:',
            'cta' => 'Open your dashboard',
        ],
    ];

    /** Local hour (in each lot's time zone) the automated emails go out. */
    public const DEFAULT_HOUR = 10;

    /**
     * Everything in force, per rule, plus the send hour.
     *
     * @return array{hour: int, rules: array<string, array{enabled: bool, threshold: int, cooldown: int, subject: string, intro: string}>}
     */
    public static function current(): array
    {
        $saved = self::saved();
        $rules = [];
        foreach (self::RULES as $key => $rule) {
            $o = (array) ($saved['rules'][$key] ?? []);
            $rules[$key] = [
                'enabled' => (bool) ($o['enabled'] ?? true),
                'threshold' => max(1, (int) ($o['threshold'] ?? $rule['threshold'])),
                'cooldown' => max(1, (int) ($o['cooldown'] ?? $rule['cooldown'])),
                'subject' => trim((string) ($o['subject'] ?? '')) ?: $rule['subject'],
                'intro' => trim((string) ($o['intro'] ?? '')) ?: $rule['intro'],
            ];
        }

        return ['hour' => min(23, max(0, (int) ($saved['hour'] ?? self::DEFAULT_HOUR))), 'rules' => $rules];
    }

    /** @return array{enabled: bool, threshold: int, cooldown: int, subject: string, intro: string} */
    public static function get(string $rule): array
    {
        return self::current()['rules'][$rule];
    }

    /** @param  array{hour: int, rules: array<string, array{enabled: bool, threshold: int, cooldown: int, subject: string, intro: string}>}  $values */
    public static function save(array $values, ?User $by = null): void
    {
        $before = self::current();
        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $values, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        AuditLog::record('admin.engagement_rules_changed', null, ['before' => $before, 'after' => self::current()], $by);
    }

    /** "{count} cars at {lot}" → "3 cars at Prime Motors". */
    public static function fill(string $text, array $values): string
    {
        return strtr($text, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return array<string, mixed> */
    private static function saved(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, function (): array {
                $setting = PlatformSetting::query()->where('key', self::KEY)->first();

                return $setting === null ? [] : $setting->value;
            });
        } catch (Throwable) {
            return [];
        }
    }
}
