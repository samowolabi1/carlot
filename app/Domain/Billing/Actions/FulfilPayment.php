<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Appointments\Actions\ConfirmDeposit;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Gateways\GatewayTransaction;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use Illuminate\Support\Facades\DB;

class FulfilPayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly ActivateSubscription $activate,
        private readonly ActivateSpotlight $spotlight,
        private readonly ConfirmDeposit $deposit,
        private readonly RefundPayment $refund,
    ) {}

    /**
     * Runs once per payment, from the checkout callback or the webhook, whichever comes
     * first. The provider is asked directly; the amount must match what we asked for.
     */
    public function run(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $transaction = $this->gateway->verify($payment->reference);

        return DB::transaction(function () use ($payment, $transaction): Payment {
            $locked = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== PaymentStatus::Pending) {
                return $locked;
            }

            if (! $transaction->successful) {
                $locked->forceFill(['status' => PaymentStatus::Failed, 'meta' => [...($locked->meta ?? []), 'reason' => $transaction->message]])->save();

                return $locked;
            }

            if ($transaction->amount < $locked->amount || $transaction->currency !== $locked->currency) {
                $locked->forceFill(['status' => PaymentStatus::Failed, 'meta' => [...($locked->meta ?? []), 'reason' => 'Amount did not match', 'paid' => $transaction->amount]])->save();
                AuditLog::record('billing.amount_mismatch', $locked, ['expected' => $locked->amount, 'paid' => $transaction->amount], lotId: $locked->lot_id);

                return $locked;
            }

            $locked->forceFill(['status' => PaymentStatus::Success, 'paid_at' => $transaction->paidAt ?? now()])->save();
            $this->deliver($locked, $transaction);

            return $locked;
        });
    }

    private function deliver(Payment $payment, GatewayTransaction $transaction): void
    {
        match ($payment->purpose) {
            PaymentPurpose::Subscription => $this->activate->run($payment, $transaction),
            PaymentPurpose::Spotlight => $this->spotlight->run(Spotlight::withoutGlobalScopes()->findOrFail($payment->payable_id)),
            PaymentPurpose::Renewal => null,
            // Buyer checkouts started before LotLink stopped taking payments for cars. A reservation
            // is now paid to the lot directly, so an old one is closed and the money goes back;
            // a test-drive deposit still confirms its booking (or goes back if the slot was released).
            PaymentPurpose::Reservation => $this->closeLegacyReservation($payment),
            PaymentPurpose::Deposit => $this->deposit->run($payment) ?: $this->refund->run($payment),
        };
    }

    private function closeLegacyReservation(Payment $payment): void
    {
        Reservation::withoutGlobalScopes()->whereKey($payment->payable_id)->where('status', ReservationStatus::Pending)
            ->update(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => 'Paid online after LotLink stopped taking payments; refunded']);
        $this->refund->run($payment);
    }
}
