<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use Illuminate\Validation\ValidationException;

class CancelAppointment
{
    public function __construct(private readonly NotifyLot $notifyLot, private readonly RefundDeposit $refundDeposit) {}

    public function run(Appointment $appointment, ?User $by, ?string $reason = null): Appointment
    {
        if (! $appointment->isActive()) {
            throw ValidationException::withMessages(['status' => 'This booking is already closed.']);
        }

        $appointment->forceFill([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by?->id,
            'cancel_reason' => $reason,
        ])->save();

        // Cancelling before the slot (by either side) returns a test-drive deposit.
        $this->refundDeposit->run($appointment, $by);

        if ($by !== null && $by->id === $appointment->customer_id) {
            $this->notifyLot->run($appointment, 'cancelled');
        } else {
            $appointment->customer->notify(new BookingNotice($appointment, BookingNotice::CANCELLED));
        }

        return $appointment;
    }
}
