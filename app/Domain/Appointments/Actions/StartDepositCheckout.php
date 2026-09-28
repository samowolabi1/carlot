<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Deals\Support\BuyerCheckout;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class StartDepositCheckout
{
    public const HOLD_MINUTES = 30;

    public function __construct(private readonly BuyerCheckout $checkout) {}

    /** Sends the buyer to pay a test drive's refundable deposit (spec: cut no-shows). */
    public function run(Appointment $appointment): string
    {
        $lot = Lot::findOrFail($appointment->lot_id);
        $deposit = $lot->testDriveDeposit();

        if ($appointment->status !== AppointmentStatus::AwaitingDeposit || $deposit === null) {
            throw ValidationException::withMessages(['deposit' => 'There is no deposit to pay for this booking.']);
        }

        $payment = $this->checkout->payment($appointment, $appointment->customer, $lot, PaymentPurpose::Deposit, $deposit, (string) config('lotlink.currency', 'NGN'), "Test-drive deposit: {$lot->name}");
        $appointment->forceFill(['deposit_payment_id' => $payment->id])->save();

        return $this->checkout->url($payment, $appointment->customer, route('bookings.deposit.callback'));
    }
}
