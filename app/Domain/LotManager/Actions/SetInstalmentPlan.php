<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\InstalmentSchedule;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetInstalmentPlan
{
    public const MAX = 24;

    public const FREQUENCIES = ['weekly' => 'Every week', 'fortnightly' => 'Every 2 weeks', 'monthly' => 'Every month'];

    /**
     * Splits what is left to pay into up to 24 dated amounts (TDD M19). Amounts are whole naira;
     * the last one takes the remainder. Setting a new plan replaces the old schedule.
     */
    public function run(SalesOrder $order, User $user, int $count, string $firstDue, string $frequency): SalesOrder
    {
        $lot = Lot::findOrFail($order->lot_id);

        if (! $lot->planAllows('instalments')) {
            throw ValidationException::withMessages(['count' => 'Instalment plans come with the Starter plan and up.']);
        }

        if ($count < 1 || $count > self::MAX || ! isset(self::FREQUENCIES[$frequency])) {
            throw ValidationException::withMessages(['count' => 'Choose between 1 and 24 instalments.']);
        }

        $first = CarbonImmutable::parse($firstDue, $lot->timezone)->startOfDay();
        if ($first->lt(now($lot->timezone)->startOfDay())) {
            throw ValidationException::withMessages(['first_due' => 'The first instalment can\'t be in the past.']);
        }

        return DB::transaction(function () use ($order, $user, $count, $first, $frequency): SalesOrder {
            $locked = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isOpen() || $locked->balance <= 0) {
                throw ValidationException::withMessages(['count' => 'Only an open order with money still to pay can have a plan.']);
            }

            $each = intdiv(intdiv($locked->balance, 100), $count) * 100;
            if ($each <= 0) {
                throw ValidationException::withMessages(['count' => 'That is too many instalments for the amount left.']);
            }

            Instalment::query()->where('sales_order_id', $locked->id)->delete();

            for ($i = 0; $i < $count; $i++) {
                $due = match ($frequency) {
                    'weekly' => $first->addWeeks($i),
                    'fortnightly' => $first->addWeeks($i * 2),
                    default => $first->addMonthsNoOverflow($i),
                };

                Instalment::create([
                    'lot_id' => $locked->lot_id,
                    'sales_order_id' => $locked->id,
                    'sequence' => $i + 1,
                    'due_date' => $due->toDateString(),
                    'amount' => $i === $count - 1 ? $locked->balance - $each * ($count - 1) : $each,
                ]);
            }

            $locked->forceFill(['payment_plan' => 'instalments', 'instalments_from_paid' => $locked->total_paid])->save();
            InstalmentSchedule::apply($locked);

            AuditLog::record('order.instalments', $locked, ['count' => $count, 'frequency' => $frequency, 'first' => $first->toDateString(), 'balance' => $locked->balance], $user);

            return $locked;
        });
    }

    public function clear(SalesOrder $order, User $user): SalesOrder
    {
        return DB::transaction(function () use ($order, $user): SalesOrder {
            $locked = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            Instalment::query()->where('sales_order_id', $locked->id)->delete();
            $locked->forceFill(['payment_plan' => 'full', 'instalments_from_paid' => null])->save();
            AuditLog::record('order.instalments_cleared', $locked, [], $user);

            return $locked;
        });
    }
}
