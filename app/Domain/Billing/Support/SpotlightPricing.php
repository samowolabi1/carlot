<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Lots\Models\Lot;

/** Spotlight prices and the plan's free monthly car spotlights (TDD M5). */
final class SpotlightPricing
{
    /** Minor units, or null if that length isn't sold. */
    public static function price(SpotlightPlacement $placement, int $days): ?int
    {
        $naira = config("lotlink.billing.spotlight.{$placement->value}.{$days}");

        return $naira === null ? null : (int) $naira * 100;
    }

    /** @return list<array{days: int, price: int}> whole naira */
    public static function options(SpotlightPlacement $placement): array
    {
        /** @var array<int, int> $prices */
        $prices = config("lotlink.billing.spotlight.{$placement->value}", []);

        return array_map(fn (int $days, int $price) => ['days' => $days, 'price' => $price], array_keys($prices), $prices);
    }

    /** Free 7-day car spotlights left this calendar month (Pro: 2). */
    public static function freeLeft(Lot $lot): int
    {
        $allowance = (int) ($lot->plan->free_spotlights ?? 0);

        if ($allowance === 0) {
            return 0;
        }

        $used = Spotlight::withoutGlobalScopes()->where('lot_id', $lot->id)->where('free', true)
            ->where('created_at', '>=', now($lot->timezone)->startOfMonth()->utc())
            ->count();

        return max(0, $allowance - $used);
    }
}
