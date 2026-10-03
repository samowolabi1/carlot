<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class StartReservation
{
    public function __construct(private readonly CaptureLead $capture, private readonly DealTimeline $timeline) {}

    /**
     * "Reserve this car" (design 11): records the request and shows the buyer the seller's bank
     * details. The buyer transfers the deposit to the seller, never to CarYard; the car is only held
     * once the seller confirms the money arrived (ActivateReservation). Asking again returns the same request.
     */
    public function run(User $customer, Vehicle $vehicle, int $hours): Reservation
    {
        $lot = Lot::findOrFail($vehicle->lot_id);
        $deposit = $lot->reservationDeposit();

        if ($deposit === null || LotBankAccount::preferredFor($lot->id) === null) {
            throw ValidationException::withMessages(['hours' => "{$lot->name} doesn't take reservations on CarYard."]);
        }

        if ($customer->hasLotRole($lot)) {
            throw ValidationException::withMessages(['hours' => 'You work for this seller.']);
        }

        if (! in_array($hours, Reservation::HOURS, true)) {
            throw ValidationException::withMessages(['hours' => 'Hold it for 24, 48 or 72 hours.']);
        }

        if ($vehicle->status !== VehicleStatus::Available || Reservation::activeFor($vehicle->id) !== null) {
            throw ValidationException::withMessages(['hours' => 'Someone has just reserved this car.']);
        }

        $open = Reservation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $customer->id)
            ->where('status', ReservationStatus::Pending)->first();
        if ($open !== null) {
            return $open;
        }

        // An accepted offer sets the price the deposit counts towards.
        $offer = Offer::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $customer->id)
            ->where('status', OfferStatus::Accepted)->latest('id')->first();

        $lead = $this->capture->run($lot, $customer, LeadSource::Reservation, $vehicle);

        $reservation = Reservation::withoutGlobalScopes()->create([
            'lot_id' => $lot->id,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'offer_id' => $offer?->id,
            'amount' => $deposit,
            'price' => $offer?->agreedAmount() ?? (int) $vehicle->price,
            'currency' => $vehicle->currency,
            'hours' => $hours,
            'pay_by' => now()->addHours(Reservation::PAY_WITHIN_HOURS),
            'status' => ReservationStatus::Pending,
        ]);

        $who = Name::short($customer->name);
        $this->timeline->post($lead, "Asked to reserve for {$hours} hours · paying {$reservation->money()} by transfer, reference {$reservation->reference}");
        Notification::send($lot->members()->get(), new DealAlert('reservation',
            "{$who} wants to reserve the {$vehicle->title()} and is sending {$reservation->money()} to your account (reference {$reservation->reference}). Confirm it when the money lands.",
            DealLinks::lot($lot, 'reservations')));

        return $reservation;
    }
}
