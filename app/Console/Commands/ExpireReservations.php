<?php

namespace App\Console\Commands;

use App\Domain\Deals\Actions\EndReservation;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use Illuminate\Console\Command;

/**
 * Every 5 minutes (TDD: reservations:expire): holds that ran out release the car and refund
 * per the lot's policy; checkouts abandoned for an hour are closed.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release expired reservations and refund per the lot policy';

    public function handle(EndReservation $end): int
    {
        $expired = 0;
        Reservation::withoutGlobalScopes()->where('status', ReservationStatus::Active)->where('expires_at', '<=', now())
            ->each(function (Reservation $reservation) use ($end, &$expired): void {
                $end->run($reservation, ReservationStatus::Expired, 'Hold ended');
                $expired++;
            });

        $abandoned = Reservation::withoutGlobalScopes()->where('status', ReservationStatus::Pending)->where('created_at', '<=', now()->subHour())
            ->update(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => 'Not paid']);

        $this->info("Expired {$expired} reservations; closed {$abandoned} unpaid.");

        return self::SUCCESS;
    }
}
