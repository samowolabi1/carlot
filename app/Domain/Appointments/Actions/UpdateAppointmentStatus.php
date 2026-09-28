<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use Illuminate\Validation\ValidationException;

/** Dealer-side steps: confirm a request, check the buyer in, mark a no-show, complete. */
class UpdateAppointmentStatus
{
    public const ACTIONS = ['confirm', 'check_in', 'no_show', 'complete'];

    public function __construct(private readonly RefundDeposit $refundDeposit) {}

    public function run(Appointment $appointment, string $action): Appointment
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['action' => $message]);

        match ($action) {
            'confirm' => $appointment->status === AppointmentStatus::Pending
                ? $appointment->forceFill(['status' => AppointmentStatus::Confirmed, 'confirmed_at' => now()])
                : $fail('Only requests waiting for confirmation can be confirmed.'),
            'check_in' => $appointment->isActive() && $appointment->checked_in_at === null && $appointment->starts_at->lte(now()->addHours(2))
                ? $appointment->forceFill(['status' => AppointmentStatus::Confirmed, 'checked_in_at' => now(), 'confirmed_at' => $appointment->confirmed_at ?? now()])
                : $fail('Check buyers in when they arrive, up to 2 hours before their slot.'),
            'no_show' => $appointment->isActive() && $appointment->checked_in_at === null && $appointment->starts_at->isPast()
                ? $appointment->forceFill(['status' => AppointmentStatus::NoShow])
                : $fail('A booking can be marked no-show once its time has passed without a check-in.'),
            'complete' => $appointment->checked_in_at !== null && $appointment->status === AppointmentStatus::Confirmed
                ? $appointment->forceFill(['status' => AppointmentStatus::Completed, 'completed_at' => now()])
                : $fail('Check the buyer in before completing the visit.'),
            default => $fail('Unknown action.'),
        };

        $appointment->save();

        if ($action === 'confirm') {
            $appointment->customer->notify(new BookingNotice($appointment, BookingNotice::CONFIRMED));
        }

        // The buyer turned up, so a test-drive deposit goes back; a no-show keeps it.
        if ($action === 'check_in') {
            $this->refundDeposit->run($appointment);
        }

        return $appointment;
    }
}
