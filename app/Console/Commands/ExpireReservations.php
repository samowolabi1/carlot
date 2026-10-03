<?php

namespace App\Console\Commands;

use App\Domain\Deals\Actions\EndReservation;
use App\Domain\Deals\Actions\ReservationDeposits;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use Illuminate\Console\Command;

/**
 * Every 5 minutes (TDD: reservations:expire): holds that ran out release the car (the seller owes
 * the deposit back if its policy says so); requests the seller never confirmed lapse.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Release expired reservations and lapse unconfirmed requests';

    public function handle(EndReservation $end, ReservationDeposits $deposits): int
    {
        $expired = 0;
        Reservation::withoutGlobalScopes()->where('status', ReservationStatus::Active)->where('expires_at', '<=', now())
            ->each(function (Reservation $reservation) use ($end, &$expired): void {
                $end->run($reservation, ReservationStatus::Expired, 'Hold ended');
                $expired++;
            });

        $lapsed = 0;
        Reservation::withoutGlobalScopes()->where('status', ReservationStatus::Pending)->where('pay_by', '<=', now())
            ->each(function (Reservation $reservation) use ($deposits, &$lapsed): void {
                $deposits->lapse($reservation);
                $lapsed++;
            });

        $this->info("Expired {$expired} reservations; {$lapsed} requests lapsed.");

        return self::SUCCESS;
    }
}
