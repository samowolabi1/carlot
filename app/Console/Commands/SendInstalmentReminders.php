<?php

namespace App\Console\Commands;

use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Notifications\InstalmentReminder;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Hourly, sending from 09:00 lot time: reminders 3 days before and on the due date (TDD M19). */
class SendInstalmentReminders extends Command
{
    protected $signature = 'manager:instalment-reminders';

    protected $description = 'Remind customers of instalments due in 3 days or today';

    public function handle(): int
    {
        $sent = 0;
        $lots = [];

        Instalment::query()->whereIn('status', [InstalmentStatus::Pending, InstalmentStatus::PartPaid])
            ->whereBetween('due_date', [now()->subDay()->toDateString(), now()->addDays(4)->toDateString()])
            ->where(fn ($q) => $q->whereNull('reminded_before_at')->orWhereNull('reminded_due_at'))
            ->each(function (Instalment $i) use (&$sent, &$lots): void {
                $order = SalesOrder::withoutGlobalScopes()->find($i->sales_order_id);
                if ($order === null || ! in_array($order->status, OrderStatus::open(), true)) {
                    return;
                }

                $lot = $lots[$order->lot_id] ??= Lot::find($order->lot_id);
                if ($lot === null) {
                    return;
                }

                $local = CarbonImmutable::now($lot->timezone);
                if ($local->hour < 9 || ! $lot->planAllows('instalments')) {
                    return;
                }

                $due = $i->due_date->toDateString();
                $today = $due === $local->toDateString() && $i->reminded_due_at === null;
                $before = $due === $local->addDays(3)->toDateString() && $i->reminded_before_at === null;
                if (! $today && ! $before) {
                    return;
                }

                $customer = LotCustomer::withoutGlobalScopes()->find($order->lot_customer_id);
                $customer?->notify(new InstalmentReminder($i, $today));
                $i->forceFill([$today ? 'reminded_due_at' : 'reminded_before_at' => now()])->save();
                $sent++;
            });

        $this->info("Sent {$sent} instalment reminders.");

        return self::SUCCESS;
    }
}
