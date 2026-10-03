<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\LotCounter;
use App\Domain\LotManager\Support\OrderLedger;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    public const REFUND = 'refund';

    public const CREDIT = 'credit';

    public function __construct(private readonly OrderLedger $ledger) {}

    /**
     * Cancels an order before delivery and frees its car. Money already paid is either
     * refunded (a negative payment with a method) or kept as the customer's credit, as
     * the seller chooses (TDD M19: Orders).
     */
    public function run(SalesOrder $order, User $user, string $reason, ?string $money = null, ?string $refundMethod = null): SalesOrder
    {
        return DB::transaction(function () use ($order, $user, $reason, $money, $refundMethod): SalesOrder {
            $locked = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['reason' => "A {$locked->status->label()} order can't be cancelled."]);
            }

            $refund = null;

            if ($locked->total_paid > 0) {
                if (! in_array($money, [self::REFUND, self::CREDIT], true)) {
                    throw ValidationException::withMessages(['money' => 'Choose whether to refund the '.$locked->money($locked->total_paid).' or keep it as credit.']);
                }

                if ($money === self::REFUND) {
                    $method = PaymentMethod::tryFrom((string) $refundMethod)
                        ?? throw ValidationException::withMessages(['refund_method' => 'How was the money refunded?']);

                    $refund = OrderPayment::create([
                        'sales_order_id' => $locked->id,
                        'lot_id' => $locked->lot_id,
                        'amount' => -$locked->total_paid,
                        'method' => $method,
                        'received_by' => $user->id,
                        'paid_at' => now(),
                        'receipt_no' => LotCounter::next(Lot::findOrFail($locked->lot_id), 'receipt'),
                    ]);
                }
            }

            $locked->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
                'notes' => $money === self::CREDIT
                    ? trim(($locked->notes ? $locked->notes."\n" : '').'Kept '.$locked->money($locked->total_paid).' as credit.')
                    : $locked->notes,
            ]);

            if ($refund !== null) {
                $this->ledger->recalculate($locked);
            } else {
                $locked->save();
                $this->ledger->syncVehicle($locked);
            }

            AuditLog::record('order.cancelled', $locked, array_filter(['reason' => $reason, 'money' => $money, 'refund' => $refund?->amount]), $user);

            return $locked;
        });
    }
}
