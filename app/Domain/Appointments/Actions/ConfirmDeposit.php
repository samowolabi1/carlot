<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Lots\Models\Lot;

class ConfirmDeposit
{
    public function __construct(private readonly BookAppointment $book) {}

    /**
     * Runs from FulfilPayment: the deposit is in, so the booking goes ahead as a normal one
     * (confirmed or waiting for the seller). If the hold ran out first, the deposit goes back.
     *
     * @return bool false when the deposit must be refunded
     */
    public function run(Payment $payment): bool
    {
        $appointment = Appointment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->payable_id);

        if ($appointment->status !== AppointmentStatus::AwaitingDeposit) {
            return $appointment->deposit_payment_id === $payment->id && $appointment->isActive();
        }

        $lot = Lot::findOrFail($appointment->lot_id);
        $auto = $lot->booking_auto_confirm;

        $appointment->forceFill([
            'status' => $auto ? AppointmentStatus::Confirmed : AppointmentStatus::Pending,
            'confirmed_at' => $auto ? now() : null,
            'deposit_payment_id' => $payment->id,
        ])->save();

        $this->book->announce($appointment, $lot, $appointment->customer, $appointment->vehicle_id ? $appointment->vehicle : null);

        return true;
    }
}
