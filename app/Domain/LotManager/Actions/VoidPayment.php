<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidPayment
{
    public function __construct(private readonly OrderLedger $ledger) {}

    /** Payments are never deleted: a mistake is voided with a reason and the totals rerun. */
    public function run(OrderPayment $payment, User $user, string $reason): OrderPayment
    {
        return DB::transaction(function () use ($payment, $user, $reason): OrderPayment {
            $order = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->sales_order_id);
            $payment->refresh();

            if ($payment->isVoid()) {
                throw ValidationException::withMessages(['reason' => 'This payment is already void.']);
            }

            if (! $order->isOpen()) {
                throw ValidationException::withMessages(['reason' => "The order is {$order->status->label()}; its payments can't change."]);
            }

            $payment->forceFill(['voided_at' => now(), 'void_reason' => $reason])->save();
            $this->ledger->recalculate($order);

            AuditLog::record('payment.voided', $payment, ['amount' => $payment->amount, 'receipt_no' => $payment->receipt_no, 'reason' => $reason], $user, $order->lot_id);

            return $payment;
        });
    }
}
