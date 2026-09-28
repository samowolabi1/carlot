<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Notifications\PaymentReceipt;
use App\Domain\LotManager\Support\LotCounter;
use App\Domain\LotManager\Support\OrderLedger;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    public function __construct(private readonly OrderLedger $ledger) {}

    /**
     * Locks the order row, inserts the payment with the lot's next receipt number,
     * recalculates the totals, moves the order (and its car) along and sends the receipt
     * (TDD M19: Payments). Safe to replay with the same client_uuid.
     *
     * @param  array{amount: int, method: string, reference?: ?string, paid_at?: ?string, client_uuid?: ?string}  $data
     */
    public function run(SalesOrder $order, User $staff, array $data): OrderPayment
    {
        if (filled($data['client_uuid'] ?? null) && ($existing = $this->find($order, $data['client_uuid']))) {
            return $existing;
        }

        if ($data['amount'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }

        $paidAt = filled($data['paid_at'] ?? null) ? Carbon::parse($data['paid_at'])->utc() : now();

        try {
            $payment = DB::transaction(function () use ($order, $staff, $data, $paidAt): OrderPayment {
                $locked = SalesOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);

                if (! $locked->isOpen()) {
                    throw ValidationException::withMessages(['amount' => "This order is {$locked->status->label()}; it can't take payments."]);
                }

                if ($data['amount'] > $locked->balance) {
                    throw ValidationException::withMessages(['amount' => $locked->balance > 0
                        ? 'That is more than the '.$locked->money($locked->balance).' still to pay.'
                        : 'This order is already fully paid.']);
                }

                $lot = Lot::findOrFail($locked->lot_id);

                $payment = OrderPayment::create([
                    'sales_order_id' => $locked->id,
                    'lot_id' => $locked->lot_id,
                    'amount' => $data['amount'],
                    'method' => PaymentMethod::from($data['method']),
                    'reference' => $data['reference'] ?? null,
                    'received_by' => $staff->id,
                    'paid_at' => $paidAt->isFuture() ? now() : $paidAt,
                    'receipt_no' => LotCounter::next($lot, 'receipt'),
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);

                $this->ledger->recalculate($locked);
                $order->setRawAttributes($locked->getAttributes(), true);

                AuditLog::record('payment.recorded', $payment, ['amount' => $payment->amount, 'receipt_no' => $payment->receipt_no], $staff, $locked->lot_id);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($order, (string) ($data['client_uuid'] ?? '')) ?? throw $e;
        }

        $this->sendReceipt($payment, $order);

        return $payment;
    }

    /** WhatsApp (else SMS) only with the customer's consent; email when we have one. */
    public function sendReceipt(OrderPayment $payment, SalesOrder $order): void
    {
        $customer = $order->customer()->first();

        if ($customer !== null && ($customer->consent_whatsapp || filled($customer->email))) {
            $customer->notify(new PaymentReceipt($payment));
        }
    }

    private function find(SalesOrder $order, string $clientUuid): ?OrderPayment
    {
        return OrderPayment::query()->where('lot_id', $order->lot_id)->where('client_uuid', $clientUuid)->first();
    }
}
