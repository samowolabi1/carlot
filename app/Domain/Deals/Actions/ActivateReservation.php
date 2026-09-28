<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\Notification;

class ActivateReservation
{
    public function __construct(private readonly VehicleStateMachine $stateMachine, private readonly DealTimeline $timeline) {}

    /**
     * Runs from FulfilPayment once the deposit is verified. The car row is locked; if it was
     * sold or reserved while the buyer paid, the reservation fails and the deposit goes back.
     *
     * @return bool false when the deposit must be refunded
     */
    public function run(Payment $payment): bool
    {
        $reservation = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->payable_id);

        if ($reservation->status !== ReservationStatus::Pending) {
            return $reservation->status === ReservationStatus::Active;
        }

        $vehicle = Vehicle::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->vehicle_id);
        $lot = Lot::findOrFail($reservation->lot_id);
        $car = $vehicle->title();

        if ($vehicle->status !== VehicleStatus::Available || Reservation::activeFor($vehicle->id) !== null) {
            $reservation->forceFill(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => 'The car was taken while you paid'])->save();
            $reservation->customer->notify(new DealUpdate('reservation', $car, $lot->name, "Someone else bought or reserved it while you paid. Your {$reservation->money()} is being refunded.", DealLinks::buyer()));

            return false;
        }

        $this->stateMachine->transition($vehicle, VehicleStatus::Reserved);
        $reservation->forceFill([
            'status' => ReservationStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addHours($reservation->hours),
        ])->save();

        $until = $reservation->expires_at?->copy()->setTimezone($lot->timezone)->format('D j M, g:ia');
        $who = Name::short($reservation->customer->name);

        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), "Reserved with a {$reservation->money()} deposit until {$until}", LeadStage::Negotiating);
        $reservation->customer->notify(new DealUpdate('reservation', $car, $lot->name, "It's reserved for you until {$until}. Your {$reservation->money()} deposit counts towards the price.", DealLinks::buyer()));
        Notification::send($lot->members()->get(), new DealAlert('reservation', "{$who} reserved the {$car} with a {$reservation->money()} deposit, until {$until}.", DealLinks::lot($lot, 'reservations')));

        return true;
    }
}
