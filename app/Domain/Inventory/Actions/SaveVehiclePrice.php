<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Events\VehiclePriceDropped;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Sharing\Jobs\RenderShareCard;
use Illuminate\Support\Facades\DB;

class SaveVehiclePrice
{
    /**
     * Sets the asking price (minor units). Once a car has been listed, every change is
     * kept in vehicle_price_history and a drop fires VehiclePriceDropped.
     */
    public function run(Vehicle $vehicle, User $user, int $price, bool $negotiable): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $user, $price, $negotiable): Vehicle {
            $old = $vehicle->price;
            $vehicle->negotiable = $negotiable;

            if ($old === $price) {
                $vehicle->save();

                return $vehicle;
            }

            $vehicle->price = $price;

            if ($vehicle->listed_at !== null && $old !== null) {
                $vehicle->price_changed_at = now();
                $vehicle->priceHistory()->create([
                    'old_price' => $old,
                    'new_price' => $price,
                    'changed_by' => $user->id,
                ]);
            }

            $vehicle->save();

            RenderShareCard::refresh($vehicle->id);

            if ($vehicle->listed_at !== null && $old !== null && $price < $old) {
                DB::afterCommit(fn () => VehiclePriceDropped::dispatch($vehicle, $old, $price));
            }

            return $vehicle;
        });
    }
}
