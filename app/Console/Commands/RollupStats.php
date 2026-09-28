<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Analytics\Support\RollupDailyStats;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hourly (TDD: stats:rollup): today's and yesterday's numbers in each lot's timezone go into
 * daily_vehicle_stats, so yesterday is final after 01:00 lot time. Raw events older than 90
 * days are removed; the rollups stay.
 */
class RollupStats extends Command
{
    protected $signature = 'stats:rollup {--days=2 : How many local days back to recompute}';

    protected $description = 'Roll analytics events up into daily per-car stats';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days + 1);
        $rows = 0;

        Lot::withTrashed()->whereIn('id', AnalyticsEvent::query()->where('occurred_at', '>=', $since)->distinct()->select('lot_id'))
            ->each(function (Lot $lot) use ($days, &$rows): void {
                $today = CarbonImmutable::now($lot->timezone);
                for ($i = 0; $i < $days; $i++) {
                    $rows += RollupDailyStats::day($lot, $today->subDays($i));
                }
            });

        $pruned = AnalyticsEvent::query()->where('occurred_at', '<', now()->subDays(90))->delete();
        $this->info("Rolled up {$rows} car-days; pruned {$pruned} old events.");

        return self::SUCCESS;
    }
}
