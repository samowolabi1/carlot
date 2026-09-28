<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\DepositRefunded;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;

class RefundDeposit
{
    public function __construct(private readonly RefundPayment $refund) {}

    /**
     * The test-drive deposit goes back when the buyer arrives, when the lot cancels, or when
     * the buyer cancels before the slot. It is kept only for a no-show.
     */
    public function run(Appointment $appointment, ?User $by = null): void
    {
        $payment = $appointment->deposit_payment_id ? Payment::find($appointment->deposit_payment_id) : null;

        if ($payment === null || $payment->status !== PaymentStatus::Success) {
            return;
        }

        $this->refund->run($payment, $by);
        $appointment->customer->notify(new DepositRefunded($appointment, $payment));
    }
}
