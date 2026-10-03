<?php

namespace App\Console\Commands;

use App\Domain\Appointments\Actions\NotifyLot;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use Illuminate\Console\Command;

/** Hourly: booking requests left unconfirmed for 4 hours go to the seller (TDD M7). */
class EscalatePendingAppointments extends Command
{
    protected $signature = 'appointments:escalate-pending';

    protected $description = 'Alert sellers about booking requests waiting more than 4 hours';

    public function handle(NotifyLot $notifyLot): int
    {
        $count = 0;

        Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Pending)
            ->whereNull('escalated_at')
            ->where('created_at', '<=', now()->subHours(4))
            ->where('starts_at', '>', now())
            ->each(function (Appointment $a) use ($notifyLot, &$count): void {
                $notifyLot->run($a, 'escalated', ownerOnly: true);
                $a->forceFill(['escalated_at' => now()])->save();
                $count++;
            });

        $this->info("Escalated {$count} requests.");

        return self::SUCCESS;
    }
}
