<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The deposit moves between buyer and lot outside CarYard; these record what each side says:
 * the buyer sent it, the seller declined the request, or the seller refunded it. (Confirming it
 * arrived is ActivateReservation.)
 */
class ReservationDeposits
{
    public function __construct(private readonly DealTimeline $timeline) {}

    /** "I've sent the transfer": nudges the seller to check its account. */
    public function sent(Reservation $reservation, User $buyer): Reservation
    {
        if ($reservation->customer_id !== $buyer->id || $reservation->status !== ReservationStatus::Pending) {
            throw ValidationException::withMessages(['reservation' => 'This request is no longer waiting for payment.']);
        }

        if ($reservation->buyer_paid_at === null) {
            $reservation->forceFill(['buyer_paid_at' => now()])->save();
            $lot = Lot::findOrFail($reservation->lot_id);
            $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), "Buyer says the {$reservation->money()} transfer is sent (reference {$reservation->reference})");
            Notification::send($lot->members()->get(), new DealAlert('reservation',
                Name::short($buyer->name)." says they sent {$reservation->money()} for the {$reservation->vehicle->title()} (reference {$reservation->reference}). Check your account and confirm.",
                DealLinks::lot($lot, 'reservations')));
        }

        return $reservation;
    }

    /** The seller turns a request down (car sold elsewhere, money never came, ...). */
    public function decline(Reservation $reservation, User $by, string $reason): Reservation
    {
        $reservation = DB::transaction(function () use ($reservation, $by, $reason) {
            $locked = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== ReservationStatus::Pending) {
                throw ValidationException::withMessages(['reservation' => 'This request has already been answered.']);
            }

            $locked->forceFill(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => $reason, 'refund_due' => $locked->buyer_paid_at !== null])->save();
            AuditLog::record('reservation.declined', $locked, ['reason' => $reason], $by, $locked->lot_id);

            return $locked;
        });

        $lot = Lot::findOrFail($reservation->lot_id);
        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), "Reservation request declined: {$reason}");
        $reservation->customer->notify(new DealUpdate('reservation', $reservation->vehicle->title(), $lot->name,
            "{$lot->name} couldn't take your reservation ({$reason}).".($reservation->refund_due ? " If you sent the {$reservation->money()} deposit, they will refund it to you directly." : ''),
            DealLinks::buyer()));

        return $reservation;
    }

    /** reservations:expire: the seller never confirmed the transfer in time. */
    public function lapse(Reservation $reservation): void
    {
        $lapsed = DB::transaction(function () use ($reservation) {
            $locked = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== ReservationStatus::Pending) {
                return null;
            }
            $locked->forceFill(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => 'Not confirmed in time', 'refund_due' => $locked->buyer_paid_at !== null])->save();

            return $locked;
        });

        if ($lapsed === null) {
            return;
        }

        $lot = Lot::findOrFail($lapsed->lot_id);
        $lapsed->customer->notify(new DealUpdate('reservation', $lapsed->vehicle->title(), $lot->name,
            "Your reservation request lapsed because {$lot->name} didn't confirm a deposit in time.".($lapsed->refund_due ? " If you sent the {$lapsed->money()}, contact them for a refund." : ''),
            DealLinks::buyer()));
    }

    /** The seller sent a deposit back from its own account. */
    public function refunded(Reservation $reservation, User $by): Reservation
    {
        if (! $reservation->refund_due || $reservation->refunded_at !== null) {
            throw ValidationException::withMessages(['reservation' => 'There is no refund to record for this reservation.']);
        }

        $reservation->forceFill(['refunded_at' => now()])->save();
        AuditLog::record('reservation.refunded', $reservation, ['amount' => $reservation->amount], $by, $reservation->lot_id);

        $lot = Lot::findOrFail($reservation->lot_id);
        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), "{$reservation->money()} deposit refunded to the buyer");
        $reservation->customer->notify(new DealUpdate('reservation', $reservation->vehicle->title(), $lot->name, "{$lot->name} has refunded your {$reservation->money()} deposit. It can take a little while to show in your account.", DealLinks::buyer()));

        return $reservation;
    }
}
