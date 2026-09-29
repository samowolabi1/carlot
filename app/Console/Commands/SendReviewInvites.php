<?php

namespace App\Console\Commands;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Trust\Notifications\ReviewInvite;
use Illuminate\Console\Command;

/** Every 15 minutes: invite buyers to review visits completed 2 hours ago (TDD scheduler). */
class SendReviewInvites extends Command
{
    protected $signature = 'reviews:invite';

    protected $description = 'Ask buyers to review visits completed at least 2 hours ago';

    public function handle(): int
    {
        $sent = 0;

        Appointment::withoutGlobalScopes()
            ->where('status', AppointmentStatus::Completed)
            ->whereNull('review_invited_at')
            ->whereBetween('completed_at', [now()->subDays(3), now()->subHours(2)])
            ->whereDoesntHave('review')
            ->with('customer')
            ->chunkById(100, function ($appointments) use (&$sent): void {
                foreach ($appointments as $appointment) {
                    // Stamp first, so an overlapping run never sends twice.
                    $claimed = Appointment::withoutGlobalScopes()->whereKey($appointment->id)->whereNull('review_invited_at')->update(['review_invited_at' => now()]);

                    if ($claimed && $appointment->customer) {
                        $appointment->customer->notify(new ReviewInvite($appointment));
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} review invites.");

        return self::SUCCESS;
    }
}
