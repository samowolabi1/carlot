<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Billing\Support\BillingEmail;
use App\Domain\Billing\Support\SpotlightPricing;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuySpotlight
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly ActivateSpotlight $activate) {}

    /**
     * Spotlight a car or feature the lot for 7, 14 or 30 days. Pro plans' free monthly
     * spotlights (7 days, cars) start straight away; paid ones start once paid.
     *
     * @return array{spotlight: Spotlight, checkout: ?string}
     */
    public function run(Lot $lot, User $user, SpotlightPlacement $placement, int $days, ?Vehicle $vehicle = null, bool $useFree = false): array
    {
        if ($lot->status !== LotStatus::Active) {
            throw ValidationException::withMessages(['days' => 'Spotlights start once LotLink has approved your lot.']);
        }

        if ($placement === SpotlightPlacement::Car) {
            if ($vehicle === null || $vehicle->lot_id !== $lot->id || $vehicle->status !== VehicleStatus::Available) {
                throw ValidationException::withMessages(['days' => 'Only a car that is live on LotLink can be spotlighted.']);
            }
        } else {
            $vehicle = null;
        }

        $price = SpotlightPricing::price($placement, $days)
            ?? throw ValidationException::withMessages(['days' => 'Choose 7, 14 or 30 days.']);

        return DB::transaction(function () use ($lot, $user, $placement, $days, $vehicle, $useFree, $price): array {
            Lot::whereKey($lot->id)->lockForUpdate()->first(); // the free allowance is counted safely

            $free = $useFree && $placement === SpotlightPlacement::Car && $days === 7 && SpotlightPricing::freeLeft($lot->loadMissing('plan')) > 0;

            if ($useFree && ! $free) {
                throw ValidationException::withMessages(['days' => 'No free spotlights left this month.']);
            }

            $spotlight = Spotlight::withoutGlobalScopes()->create([
                'lot_id' => $lot->id,
                'vehicle_id' => $vehicle?->id,
                'placement' => $placement,
                'days' => $days,
                'free' => $free,
                'bought_by' => $user->id,
            ]);

            if ($free) {
                $this->activate->run($spotlight);

                return ['spotlight' => $spotlight, 'checkout' => null];
            }

            $payment = Payment::create([
                'payable_type' => $spotlight->getMorphClass(),
                'payable_id' => $spotlight->id,
                'user_id' => $user->id,
                'lot_id' => $lot->id,
                'purpose' => PaymentPurpose::Spotlight,
                'description' => $vehicle ? "Spotlight · {$vehicle->loadMissing(['make', 'model'])->title()} · {$days} days" : "Featured lot · {$days} days",
                'amount' => $price,
                'currency' => (string) config('lotlink.currency'),
                'provider' => $this->gateway->name(),
                'reference' => Payment::newReference('spot'),
            ]);
            $spotlight->forceFill(['payment_id' => $payment->id])->save();

            return ['spotlight' => $spotlight, 'checkout' => $this->gateway->checkout($payment, BillingEmail::for($lot), route('dealer.billing.callback', $lot))];
        });
    }
}
