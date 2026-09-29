<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateReservation
{
    public function __construct(private readonly VehicleStateMachine $stateMachine, private readonly DealTimeline $timeline) {}

    /**
     * The lot confirms the buyer's transfer reached its account: the car is held for the hours
     * the buyer chose. Other buyers' requests for the car lapse. Rows are locked, so two
     * confirmations can't both win.
     */
    public function run(Reservation $reservation, User $by): Reservation
    {
        [$reservation, $others] = DB::transaction(function () use ($reservation, $by) {
            $reservation = Reservation::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== ReservationStatus::Pending) {
                throw ValidationException::withMessages(['reservation' => 'This request has already been answered.']);
            }

            $vehicle = Vehicle::withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->vehicle_id);

            if ($vehicle->status !== VehicleStatus::Available || Reservation::activeFor($vehicle->id) !== null) {
                throw ValidationException::withMessages(['reservation' => 'The car is no longer available. Decline the request and refund the buyer if they paid.']);
            }

            $this->stateMachine->transition($vehicle, VehicleStatus::Reserved);
            $reservation->forceFill([
                'status' => ReservationStatus::Active,
                'activated_at' => now(),
                'confirmed_by' => $by->id,
                'expires_at' => now()->addHours($reservation->hours),
            ])->save();
            AuditLog::record('reservation.confirmed', $reservation, ['amount' => $reservation->amount, 'reference' => $reservation->reference], $by, $reservation->lot_id);

            $others = Reservation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('status', ReservationStatus::Pending)->whereKeyNot($reservation->id)->get();
            foreach ($others as $other) {
                $other->forceFill(['status' => ReservationStatus::Failed, 'ended_at' => now(), 'end_reason' => 'Reserved by another buyer', 'refund_due' => $other->buyer_paid_at !== null])->save();
            }

            return [$reservation, $others];
        });

        $lot = Lot::findOrFail($reservation->lot_id);
        $car = $reservation->vehicle->title();
        $until = $reservation->expires_at?->copy()->setTimezone($lot->timezone)->format('D j M, g:ia');

        $this->timeline->post(Lead::withoutGlobalScopes()->find($reservation->lead_id), "Deposit of {$reservation->money()} received · reserved until {$until}", LeadStage::Negotiating);
        $reservation->customer->notify(new DealUpdate('reservation', $car, $lot->name, "{$lot->name} received your {$reservation->money()} deposit. It's reserved for you until {$until}, and the deposit counts towards the price.", DealLinks::buyer()));

        foreach ($others as $other) {
            $other->customer->notify(new DealUpdate('reservation', $car, $lot->name, 'Another buyer reserved it first.'.($other->refund_due ? " If you sent the {$other->money()} deposit, {$lot->name} will refund it to you directly." : ''), DealLinks::buyer()));
        }

        return $reservation;
    }
}
