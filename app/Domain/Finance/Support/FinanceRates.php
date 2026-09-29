<?php

namespace App\Domain\Finance\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Budget and ownership rates (TDD M10). `config/lotlink.php` holds the defaults; an admin can
 * override them in /admin → Finance rates. The overrides are laid over `config('lotlink.finance')`
 * at boot and before each queued job, so `FinanceCalculator` and the pages keep reading config.
 */
final class FinanceRates
{
    public const KEY = 'finance';

    private const CACHE = 'lotlink.settings.finance';

    /** The settings an admin may change (tenors, bands and all). */
    public const EDITABLE = [
        'affordability_ratio', 'interest_rate', 'deposit_percent', 'tenor_months', 'tenors',
        'insurance_percent', 'papers', 'fuel_price', 'km_per_month', 'km_per_litre', 'servicing',
    ];

    /** @var array<string, mixed>|null the values from config/lotlink.php, before any override */
    private static ?array $defaults = null;

    /** Whether this process has laid overrides over config (so a reset elsewhere can be undone). */
    private static bool $applied = false;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('lotlink.finance');

        return self::$defaults ??= $config;
    }

    /** @return array<string, mixed> the admin's values */
    public static function overrides(): array
    {
        try {
            /** @var array<string, mixed> $value */
            $value = Cache::rememberForever(self::CACHE, function (): array {
                $setting = PlatformSetting::query()->where('key', self::KEY)->first();

                return $setting === null ? [] : $setting->value;
            });

            $value = array_intersect_key($value, array_flip(self::EDITABLE));
            // JSON turns 30.0 into 30; keep the types config/lotlink.php uses.
            foreach (['affordability_ratio', 'interest_rate', 'insurance_percent'] as $float) {
                if (isset($value[$float])) {
                    $value[$float] = (float) $value[$float];
                }
            }

            return $value;
        } catch (Throwable) {
            return []; // before migrations (fresh install) or with the database down: the defaults
        }
    }

    public static function apply(): void
    {
        $defaults = self::defaults();
        $overrides = self::overrides();

        if ($overrides !== []) {
            config(['lotlink.finance' => array_replace($defaults, $overrides)]);
            self::$applied = true;
        } elseif (self::$applied) {
            config(['lotlink.finance' => $defaults]);
            self::$applied = false;
        }
    }

    /** @param  array<string, mixed>  $values */
    public static function save(array $values, ?User $by = null): void
    {
        $before = self::overrides();
        $values = array_intersect_key($values, array_flip(self::EDITABLE));

        foreach (['km_per_litre', 'servicing'] as $bands) {
            if (isset($values[$bands]) && is_array($values[$bands])) {
                ksort($values[$bands]);
            }
        }
        if (isset($values['tenors']) && is_array($values['tenors'])) {
            $values['tenors'] = array_values(array_map('intval', $values['tenors']));
            sort($values['tenors']);
        }

        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $values, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);
        self::apply();

        AuditLog::record('admin.finance_rates_changed', null, ['before' => $before, 'after' => $values], $by);
    }

    public static function reset(?User $by = null): void
    {
        $before = self::overrides();
        PlatformSetting::query()->whereKey(self::KEY)->delete();
        Cache::forget(self::CACHE);
        self::apply();

        AuditLog::record('admin.finance_rates_reset', null, ['before' => $before], $by);
    }

    /** Test helper: forget the remembered defaults between tests. */
    public static function flush(): void
    {
        self::$defaults = null;
        self::$applied = false;
        Cache::forget(self::CACHE);
    }
}
