<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleAppointment
{
    public function __construct(
        private readonly SlotGenerator $slots,
        private readonly NotifyLot $notifyLot,
    ) {}

    /** Moves a booking to another free slot and tells the other side. */
    public function run(Appointment $appointment, CarbonImmutable $start, User $by): Appointment
    {
        if (! $appointment->isActive()) {
            throw ValidationException::withMessages(['starts_at' => 'Only upcoming bookings can be moved.']);
        }

        $lot = Lot::findOrFail($appointment->lot_id);

        DB::transaction(function () use ($appointment, $start, $lot): void {
            Lot::whereKey($lot->id)->lockForUpdate()->first();

            $slot = $this->slots->find($lot, $start->utc(), ignore: $appointment)
                ?? throw ValidationException::withMessages(['starts_at' => 'That time is no longer free. Please pick another.']);

            $appointment->forceFill([
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['starts_at']->addMinutes($slot['minutes']),
                'reminded_24h_at' => null,
                'reminded_2h_at' => null,
            ])->save();
        });

        if ($by->id === $appointment->customer_id) {
            $this->notifyLot->run($appointment, 'rescheduled');
        } else {
            $appointment->customer->notify(new BookingNotice($appointment, BookingNotice::RESCHEDULED));
        }

        return $appointment;
    }
}
