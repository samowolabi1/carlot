<?php

namespace App\Filament\Widgets;

use App\Domain\Admin\PlatformMetrics;
use App\Domain\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/** Platform metrics (TDD M17) over the last 30 days. */
class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Platform, last 30 days';

    protected ?string $description = 'Updated every 5 minutes.';

    protected function getStats(): array
    {
        // Thirty-day figures don't need to be to the second: worked out at most every 5 minutes.
        $m = Cache::remember('admin:platform-summary', now()->addMinutes(5), fn () => PlatformMetrics::summary());

        return [
            Stat::make('Active lots', number_format($m['active_lots'])),
            Stat::make('Live listings', number_format($m['live_listings'])),
            Stat::make('New users', number_format($m['new_users'])),
            Stat::make('Bookings', number_format($m['bookings'])),
            Stat::make('Recorded sales', Money::format($m['sales_value'], 'NGN') ?? '₦0')->description($m['sales_count'].' delivered in Lot Manager'),
            Stat::make('MRR', Money::format($m['mrr'], 'NGN') ?? '₦0')->description('Active paid plans'),
            Stat::make('Churn', $m['churn'] === null ? '—' : $m['churn'].'%')->description('Paid plans cancelled'),
        ];
    }
}
