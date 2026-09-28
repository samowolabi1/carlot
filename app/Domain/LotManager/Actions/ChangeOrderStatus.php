<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Notifications\OrderUpdate;
use App\Domain\LotManager\Support\OrderLedger;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeOrderStatus
{
    public function __construct(private readonly OrderLedger $ledger) {}

    /**
     * The steps staff take by hand: papers ready, then delivered. Payments move the
     * earlier steps. Delivering with money still owed needs the owner and a reason,
     * which goes in the audit log (TDD M19: Orders).
     */
    public function run(SalesOrder $order, User $user, OrderStatus $to, ?string $overrideReason = null): SalesOrder
    {
        $order = DB::transaction(function () use ($order, $user, $to, $overrideReason): SalesOrder {
            $locked = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;

            $allowed = match ($to) {
                OrderStatus::PapersReady => in_array($from, [OrderStatus::DepositPaid, OrderStatus::FullyPaid], true),
                OrderStatus::Delivered => in_array($from, [OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady], true),
                default => false,
            };

            if (! $allowed) {
                throw ValidationException::withMessages(['status' => "A {$from->label()} order can't be marked {$to->label()}."]);
            }

            if ($to === OrderStatus::Delivered && $locked->balance > 0) {
                $lot = Lot::findOrFail($locked->lot_id);

                if (! $user->hasLotRole($lot, LotRole::Owner)) {
                    throw ValidationException::withMessages(['status' => 'There is still '.$locked->money($locked->balance).' to pay. Only the owner can hand over the car before it is paid.']);
                }

                if (blank($overrideReason)) {
                    throw ValidationException::withMessages(['override_reason' => 'Say why the car is going before it is fully paid.']);
                }

                AuditLog::record('order.delivered_with_balance', $locked, ['balance' => $locked->balance, 'reason' => $overrideReason], $user);
            }

            $locked->status = $to;
            if ($to === OrderStatus::Delivered) {
                $locked->delivered_at = now();
            }
            $locked->save();

            $this->ledger->syncVehicle($locked);
            AuditLog::record('order.status', $locked, ['from' => $from->value, 'to' => $to->value], $user);

            return $locked;
        });

        $customer = $order->customer()->first();
        if ($customer?->consent_whatsapp) {
            $customer->notify(new OrderUpdate($order));
        }

        return $order;
    }
}
