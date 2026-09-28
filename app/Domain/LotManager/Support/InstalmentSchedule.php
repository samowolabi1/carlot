<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;

/**
 * Works out each instalment's paid amount and status from the order's payments (TDD M19):
 * money paid since the plan was set goes to the oldest unpaid instalment first. Recomputing
 * from the total means a voided payment or a refund puts the schedule right again.
 */
final class InstalmentSchedule
{
    public static function apply(SalesOrder $order): void
    {
        if ($order->instalments_from_paid === null) {
            return;
        }

        $tz = (string) (Lot::withTrashed()->whereKey($order->lot_id)->value('timezone') ?? config('lotlink.timezone'));
        $today = now($tz)->toDateString();
        $available = max(0, $order->total_paid - $order->instalments_from_paid);

        Instalment::query()->where('sales_order_id', $order->id)->orderBy('sequence')->get()
            ->each(function (Instalment $i) use (&$available, $today): void {
                $paid = min($i->amount, $available);
                $available -= $paid;

                $i->paid_amount = $paid;
                $i->status = match (true) {
                    $paid >= $i->amount => InstalmentStatus::Paid,
                    $i->due_date->toDateString() < $today => InstalmentStatus::Overdue,
                    $paid > 0 => InstalmentStatus::PartPaid,
                    default => InstalmentStatus::Pending,
                };
                $i->save();
            });
    }

    /** The next instalment still to pay, for the tracking page and reminders. */
    public static function next(SalesOrder $order): ?Instalment
    {
        return Instalment::query()->where('sales_order_id', $order->id)->where('status', '!=', InstalmentStatus::Paid)->orderBy('sequence')->first();
    }
}
