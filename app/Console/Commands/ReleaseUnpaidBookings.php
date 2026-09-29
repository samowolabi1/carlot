<?php

namespace App\Console\Commands;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use Illuminate\Console\Command;

/** Every 5 minutes: a test drive whose deposit wasn't paid within 30 minutes frees its slot. */
class ReleaseUnpaidBookings extends Command
{
    protected $signature = 'appointments:release-unpaid';

    protected $description = 'Cancel test drives whose deposit was not paid within 30 minutes';

    public function handle(): int
    {
        $count = Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::AwaitingDeposit)
            ->where('created_at', '<=', now()->subMinutes(30))
            ->update(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Deposit not paid']);

        $this->info("Released {$count} unpaid bookings.");

        return self::SUCCESS;
    }
}
