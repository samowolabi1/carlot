<?php

namespace App\Domain\Advertising\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prices (and banner slots) for everything lots pay LotLink to promote: homepage and search
 * banners, car spotlights and featured lots. `config/lotlink.php` holds the defaults; an admin can
 * change them in /admin → Advert prices. The overrides are laid over config at boot and before each
 * queued job, so `AdSchedule`, `SpotlightPricing` and the pages keep reading config. New prices apply
 * to new bookings; what's already booked keeps the price paid.
 */
final class AdvertPricing
{
    public const KEY = 'advert_prices';

    private const CACHE = 'lotlink.settings.advert_prices';

    /** Every promotion sells these lengths. */
    public const DAYS = [7, 14, 30];

    /** @var array{adverts: array<string, mixed>, spotlight: array<string, mixed>}|null config/lotlink.php before any override */
    private static ?array $defaults = null;

    private static bool $applied = false;

    /** @return array{adverts: array<string, mixed>, spotlight: array<string, mixed>} */
    public static function defaults(): array
    {
        return self::$defaults ??= [
            'adverts' => (array) config('lotlink.adverts'),
            'spotlight' => (array) config('lotlink.billing.spotlight'),
        ];
    }

    /**
     * The admin's values: banner slots and prices, spotlight prices (whole naira).
     *
     * @return array{home_banner?: array{slots: int, prices: array<int, int>}, search_banner?: array{slots: int, prices: array<int, int>}, car?: array<int, int>, featured_lot?: array<int, int>}
     */
    public static function overrides(): array
    {
        try {
            /** @var array<string, mixed> $value */
            $value = Cache::rememberForever(self::CACHE, function (): array {
                $setting = PlatformSetting::query()->where('key', self::KEY)->first();

                return $setting === null ? [] : $setting->value;
            });
        } catch (Throwable) {
            return []; // before migrations, or with the database down: the defaults
        }

        $prices = fn ($list) => collect((array) $list)->mapWithKeys(fn ($price, $days) => [(int) $days => (int) $price])
            ->only(self::DAYS)->sortKeys()->all();

        $out = [];
        foreach (['home_banner', 'search_banner'] as $banner) {
            if (isset($value[$banner]) && is_array($value[$banner])) {
                $out[$banner] = ['slots' => (int) ($value[$banner]['slots'] ?? 1), 'prices' => $prices($value[$banner]['prices'] ?? [])];
            }
        }
        foreach (['car', 'featured_lot'] as $spotlight) {
            if (isset($value[$spotlight])) {
                $out[$spotlight] = $prices($value[$spotlight]);
            }
        }

        return $out;
    }

    public static function apply(): void
    {
        $defaults = self::defaults();
        $o = self::overrides();

        if ($o === []) {
            if (self::$applied) {
                config(['lotlink.adverts' => $defaults['adverts'], 'lotlink.billing.spotlight' => $defaults['spotlight']]);
                self::$applied = false;
            }

            return;
        }

        $adverts = $defaults['adverts'];
        foreach (['home_banner', 'search_banner'] as $banner) {
            if (isset($o[$banner])) {
                $adverts[$banner] = [...(array) ($adverts[$banner] ?? []), ...$o[$banner]];
            }
        }
        $spotlight = $defaults['spotlight'];
        foreach (['car', 'featured_lot'] as $kind) {
            if (isset($o[$kind])) {
                $spotlight[$kind] = $o[$kind];
            }
        }

        config(['lotlink.adverts' => $adverts, 'lotlink.billing.spotlight' => $spotlight]);
        self::$applied = true;
    }

    /**
     * @param  array{home_banner: array{slots: int, prices: array<int, int>}, search_banner: array{slots: int, prices: array<int, int>}, car: array<int, int>, featured_lot: array<int, int>}  $values
     */
    public static function save(array $values, ?User $by = null): void
    {
        $before = self::current();

        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $values, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);
        self::apply();

        AuditLog::record('admin.advert_prices_changed', null, ['before' => $before, 'after' => self::current()], $by);
    }

    public static function reset(?User $by = null): void
    {
        $before = self::current();
        PlatformSetting::query()->whereKey(self::KEY)->delete();
        Cache::forget(self::CACHE);
        self::apply();

        AuditLog::record('admin.advert_prices_reset', null, ['before' => $before], $by);
    }

    /**
     * What's in force now, in the shape the admin form edits.
     *
     * @return array{home_banner: array{slots: int, prices: array<int, int>}, search_banner: array{slots: int, prices: array<int, int>}, car: array<int, int>, featured_lot: array<int, int>}
     */
    public static function current(): array
    {
        $banner = fn (string $key) => [
            'slots' => (int) config("lotlink.adverts.{$key}.slots", 1),
            'prices' => array_map('intval', (array) config("lotlink.adverts.{$key}.prices", [])),
        ];

        return [
            'home_banner' => $banner('home_banner'),
            'search_banner' => $banner('search_banner'),
            'car' => array_map('intval', (array) config('lotlink.billing.spotlight.car', [])),
            'featured_lot' => array_map('intval', (array) config('lotlink.billing.spotlight.featured_lot', [])),
        ];
    }

    /** Test helper: forget the remembered defaults between tests. */
    public static function flush(): void
    {
        self::$defaults = null;
        self::$applied = false;
        Cache::forget(self::CACHE);
    }
}
