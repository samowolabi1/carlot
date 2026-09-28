<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Analytics\Models\DailyVehicleStat;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;

/**
 * Rolls a lot's raw events for one local day into daily_vehicle_stats (TDD M15). Recomputing a
 * day from the raw rows is idempotent, so it can run as often as needed.
 */
final class RollupDailyStats
{
    private const COLUMNS = ['view' => 'views', 'save' => 'saves', 'share' => 'shares', 'lead' => 'leads', 'booking' => 'bookings'];

    public static function day(Lot $lot, CarbonImmutable $localDay): int
    {
        $start = $localDay->setTimezone($lot->timezone)->startOfDay();
        $from = $start->utc();
        $to = $start->addDay()->utc();

        $counts = AnalyticsEvent::query()->where('lot_id', $lot->id)->whereNotNull('vehicle_id')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->selectRaw('vehicle_id, type, count(*) as n')->groupBy('vehicle_id', 'type')->get();

        $rows = [];
        foreach ($counts as $c) {
            $rows[$c->vehicle_id] ??= ['vehicle_id' => $c->vehicle_id, 'lot_id' => $lot->id, 'date' => $start->toDateString(), 'views' => 0, 'saves' => 0, 'shares' => 0, 'leads' => 0, 'bookings' => 0];
            if (isset(self::COLUMNS[$c->type])) {
                $rows[$c->vehicle_id][self::COLUMNS[$c->type]] = (int) $c->getAttribute('n');
            }
        }

        if ($rows !== []) {
            DailyVehicleStat::withoutGlobalScopes()->upsert(array_values($rows), ['vehicle_id', 'date'], array_values(self::COLUMNS));
        }

        return count($rows);
    }
}
