<?php

namespace App\Console\Commands;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use Illuminate\Console\Command;

/**
 * Every 10 minutes: confirmed bookings 30 minutes past their start with no check-in
 * become no-shows (TDD M7); checked-in visits finish an hour after their slot ends.
 */
class CloseOutAppointments extends Command
{
    protected $signature = 'appointments:mark-no-shows';

    protected $description = 'Mark missed bookings as no-shows and finish checked-in visits';

    public function handle(): int
    {
        $noShows = Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->where('starts_at', '<=', now()->subMinutes(30))
            ->update(['status' => AppointmentStatus::NoShow, 'updated_at' => now()]);

        // Requests nobody confirmed simply lapse once their time has passed.
        $lapsed = Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Pending)
            ->where('starts_at', '<=', now())
            ->update(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Not confirmed in time', 'updated_at' => now()]);

        $completed = Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Confirmed)
            ->whereNotNull('checked_in_at')
            ->where('ends_at', '<=', now()->subHour())
            ->update(['status' => AppointmentStatus::Completed, 'completed_at' => now(), 'updated_at' => now()]);

        $this->info("No-shows: {$noShows}. Lapsed requests: {$lapsed}. Completed: {$completed}.");

        return self::SUCCESS;
    }
}
