<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Support\BuyerCheckout;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class StartReservation
{
    public function __construct(private readonly CaptureLead $capture, private readonly BuyerCheckout $checkout) {}

    /**
     * "Pay and reserve" (design 11): records the hold as pending and sends the buyer to pay.
     * The car is only held once the payment is verified (ActivateReservation).
     *
     * @return array{reservation: Reservation, url: string}
     */
    public function run(User $customer, Vehicle $vehicle, int $hours, ?string $channel = null): array
    {
        $lot = Lot::findOrFail($vehicle->lot_id);
        $deposit = $lot->reservationDeposit();

        if ($deposit === null) {
            throw ValidationException::withMessages(['hours' => "{$lot->name} doesn't take reservations online."]);
        }

        if ($customer->hasLotRole($lot)) {
            throw ValidationException::withMessages(['hours' => 'You work at this lot.']);
        }

        if (! in_array($hours, Reservation::HOURS, true)) {
            throw ValidationException::withMessages(['hours' => 'Hold it for 24, 48 or 72 hours.']);
        }

        if ($vehicle->status !== VehicleStatus::Available || Reservation::activeFor($vehicle->id) !== null) {
            throw ValidationException::withMessages(['hours' => 'Someone has just reserved this car.']);
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
            'status' => ReservationStatus::Pending,
        ]);

        $payment = $this->checkout->payment($reservation, $customer, $lot, PaymentPurpose::Reservation, $deposit, $vehicle->currency, "Reservation: {$vehicle->title()}", $channel);
        $reservation->forceFill(['payment_id' => $payment->id])->save();

        return ['reservation' => $reservation, 'url' => $this->checkout->url($payment, $customer, route('reservations.callback'))];
    }
}
