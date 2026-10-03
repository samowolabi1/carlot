<?php

namespace App\Console\Commands;

use App\Domain\LotManager\Notifications\DailySummary;
use App\Domain\LotManager\Support\DailySummaryBuilder;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Hourly: at 19:00 in each seller's timezone the owner gets the day's summary, once (TDD M19). */
class SendDailySummaries extends Command
{
    protected $signature = 'manager:daily-summary';

    protected $description = "WhatsApp each seller the day's walk-ins, orders and money at 19:00 seller time";

    public function handle(): int
    {
        $sent = 0;

        Lot::active()->with(['owner', 'plan'])->each(function (Lot $lot) use (&$sent): void {
            $local = CarbonImmutable::now($lot->timezone);

            if ($local->hour < 19 || $lot->daily_summary_sent_on?->toDateString() === $local->toDateString() || ! $lot->planAllows('daily_summary') || $lot->owner === null) {
                return;
            }

            $lot->owner->notify(new DailySummary($lot, DailySummaryBuilder::for($lot, $local), route('dealer.manager.reports', [$lot, 'type' => 'sales', 'period' => '30d'])));
            $lot->forceFill(['daily_summary_sent_on' => $local->toDateString()])->save();
            $sent++;
        });

        $this->info("Sent {$sent} daily summaries.");

        return self::SUCCESS;
    }
}
