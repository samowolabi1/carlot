<?php

namespace App\Console\Commands;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\AppointmentReminder;
use Illuminate\Console\Command;

/** Every 5 minutes: queue the 24-hour and 2-hour reminders that are due (TDD M7). */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:remind';

    protected $description = 'Send 24-hour and 2-hour reminders for confirmed bookings';

    public function handle(): int
    {
        $sent = 0;

        Appointment::withoutGlobalScopes()
            ->with('customer')
            ->where('status', AppointmentStatus::Confirmed)
            ->whereNull('checked_in_at')
            ->whereBetween('starts_at', [now(), now()->addHours(24)])
            ->where(fn ($q) => $q->whereNull('reminded_24h_at')->orWhereNull('reminded_2h_at'))
            ->each(function (Appointment $a) use (&$sent): void {
                $within2h = $a->starts_at->lte(now()->addHours(2));

                if ($within2h && $a->reminded_2h_at === null) {
                    $a->customer->notify(new AppointmentReminder($a, '2h'));
                    // A booking made less than a day ahead never needs the 24-hour one.
                    $a->forceFill(['reminded_2h_at' => now(), 'reminded_24h_at' => $a->reminded_24h_at ?? now()])->save();
                    $sent++;
                } elseif (! $within2h && $a->reminded_24h_at === null) {
                    // Only bookings made at least a day ahead get the 24-hour reminder;
                    // for later bookings the confirmation has only just gone out.
                    if ($a->created_at->lte($a->starts_at->copy()->subHours(24))) {
                        $a->customer->notify(new AppointmentReminder($a, '24h'));
                        $sent++;
                    }
                    $a->forceFill(['reminded_24h_at' => now()])->save();
                }
            });

        $this->info("Queued {$sent} reminders.");

        return self::SUCCESS;
    }
}
