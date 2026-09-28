<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class MakeOffer
{
    public function __construct(private readonly CaptureLead $capture, private readonly DealTimeline $timeline) {}

    /**
     * A buyer's offer (TDD M12): at least half the asking price to keep out spam, and no more
     * than the asking price. A new offer on the same car replaces the buyer's open one.
     */
    public function run(User $customer, Vehicle $vehicle, int $amount, ?string $message = null): Offer
    {
        $lot = Lot::findOrFail($vehicle->lot_id);
        $price = (int) $vehicle->price;

        if (! $lot->takesOffers() || ! $vehicle->negotiable || $price <= 0 || $vehicle->status !== VehicleStatus::Available) {
            throw ValidationException::withMessages(['amount' => 'This car is not taking offers.']);
        }

        if ($customer->hasLotRole($lot)) {
            throw ValidationException::withMessages(['amount' => 'You work at this lot.']);
        }

        if ($amount < intdiv($price, 2)) {
            throw ValidationException::withMessages(['amount' => 'Offers must be at least '.Money::format(intdiv($price, 2), $vehicle->currency).' (half the asking price).']);
        }

        if ($amount > $price) {
            throw ValidationException::withMessages(['amount' => "That's above the asking price of ".Money::format($price, $vehicle->currency).'.']);
        }

        $lead = $this->capture->run($lot, $customer, LeadSource::Offer, $vehicle);

        $offer = DB::transaction(function () use ($lot, $customer, $vehicle, $amount, $message, $lead): Offer {
            Offer::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $customer->id)
                ->whereIn('status', OfferStatus::open())->lockForUpdate()->get()
                ->each(fn (Offer $old) => $old->forceFill(['status' => OfferStatus::Withdrawn, 'closed_at' => now()])->save());

            return Offer::withoutGlobalScopes()->create([
                'lot_id' => $lot->id,
                'lead_id' => $lead->id,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'amount' => $amount,
                'currency' => $vehicle->currency,
                'message' => filled($message) ? trim((string) $message) : null,
                'status' => OfferStatus::Pending,
                'expires_at' => now()->addHours(Offer::HOURS),
            ]);
        });

        $off = Offer::discount($amount, $price);
        $this->timeline->post($lead, 'Offer: '.$offer->money().($off ? " ({$off})" : '').($offer->message ? " · “{$offer->message}”" : ''));

        Notification::send($lot->members()->get(), new DealAlert(
            'offer',
            'New offer at '.$lot->name.': '.Name::short($customer->name).' offered '.$offer->money().' for the '.$vehicle->title().'.',
            DealLinks::lot($lot, 'offers'),
        ));

        return $offer;
    }
}
