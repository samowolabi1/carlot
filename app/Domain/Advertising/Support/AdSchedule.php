<?php

namespace App\Domain\Advertising\Support;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use Illuminate\Support\Carbon;

/**
 * Prices and slots for adverts. Each placement has a fixed number of slots; a campaign in review
 * or approved holds one for its dates, so a new booking starts on the first date with room.
 * Days run from midnight in CarYard's home timezone.
 */
final class AdSchedule
{
    /** Minor units, or null if that length isn't sold. */
    public static function price(AdPlacement $placement, int $days): ?int
    {
        $naira = config("lotlink.adverts.{$placement->value}.prices.{$days}");

        return $naira === null ? null : (int) $naira * 100;
    }

    /** @return list<array{days: int, price: int}> whole naira */
    public static function options(AdPlacement $placement): array
    {
        /** @var array<int, int> $prices */
        $prices = config("lotlink.adverts.{$placement->value}.prices", []);

        return array_map(fn (int $days, int $price) => ['days' => $days, 'price' => $price], array_keys($prices), $prices);
    }

    /** Midnight at the start of a date, in CarYard's timezone, as UTC. */
    public static function dayStart(Carbon|string $date): Carbon
    {
        $tz = (string) config('lotlink.timezone', 'Africa/Lagos');

        return Carbon::parse($date instanceof Carbon ? $date->toDateString() : $date, $tz)->startOfDay()->utc();
    }

    /** Today, in CarYard's timezone. */
    public static function today(): Carbon
    {
        return self::dayStart(now((string) config('lotlink.timezone', 'Africa/Lagos'))->toDateString());
    }

    /** The first start at or after $from with a free slot for $days days. */
    public static function nextStart(AdPlacement $placement, Carbon $from, int $days, ?int $ignoreId = null): Carbon
    {
        $windows = self::windows($placement, $from, $ignoreId);
        $start = $from->copy();

        for ($i = 0; $i < 200; $i++) {
            $end = $start->copy()->addDays($days);
            $overlapping = array_filter($windows, fn (array $w) => $w[0]->lt($end) && $w[1]->gt($start));

            if (count($overlapping) < $placement->slots()) {
                return $start;
            }

            // Try again from the first midnight after the earliest overlapping campaign ends.
            $firstEnd = min(array_map(fn (array $w) => $w[1], $overlapping));
            $next = self::dayStart($firstEnd->copy()->setTimezone((string) config('lotlink.timezone', 'Africa/Lagos')));
            $start = $next->lt($firstEnd) ? $next->addDay() : $next;
        }

        return $start;
    }

    public static function hasRoom(AdPlacement $placement, Carbon $start, int $days, ?int $ignoreId = null): bool
    {
        return self::nextStart($placement, $start, $days, $ignoreId)->equalTo($start);
    }

    /**
     * The dates each campaign holding a slot covers: approved ones by their schedule, ones in review by what was asked for.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private static function windows(AdPlacement $placement, Carbon $from, ?int $ignoreId): array
    {
        return AdCampaign::withoutGlobalScopes()
            ->where('placement', $placement)
            ->whereIn('status', AdStatus::holding())
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->map(function (AdCampaign $c): array {
                $start = $c->status === AdStatus::Approved && $c->starts_at !== null ? $c->starts_at : self::dayStart($c->requested_start);
                $end = $c->status === AdStatus::Approved && $c->ends_at !== null ? $c->ends_at : $start->copy()->addDays($c->days);

                return [$start, $end];
            })
            ->filter(fn (array $w) => $w[1]->gt($from))
            ->values()
            ->all();
    }
}
