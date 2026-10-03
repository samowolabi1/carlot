<?php

namespace App\Console\Commands;

use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\InstalmentSchedule;
use Illuminate\Console\Command;

/** Hourly (TDD: manager:mark-overdue): unpaid instalments past their date in the seller's time become overdue. */
class MarkOverdueInstalments extends Command
{
    protected $signature = 'manager:mark-overdue';

    protected $description = 'Mark unpaid past-due instalments as overdue';

    public function handle(): int
    {
        $orders = Instalment::query()->whereIn('status', [InstalmentStatus::Pending, InstalmentStatus::PartPaid])
            ->where('due_date', '<', now()->addDay()->toDateString())->distinct()->pluck('sales_order_id');

        SalesOrder::withoutGlobalScopes()->whereIn('id', $orders)->each(fn (SalesOrder $order) => InstalmentSchedule::apply($order));

        $this->info('Checked '.$orders->count().' orders.');

        return self::SUCCESS;
    }
}
