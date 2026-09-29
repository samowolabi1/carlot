<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Analytics\Support\Tracker;
use App\Domain\Inventory\Models\Vehicle;

/** The heart on a car: saves it with today's price (for price-drop alerts), or removes it. */
class SaveCar
{
    public function run(User $user, string $ulid): Vehicle
    {
        $car = Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($ulid))->firstOrFail();
        $changes = $user->favourites()->syncWithoutDetaching([$car->id => ['saved_price' => $car->price]]);
        if ($changes['attached'] !== []) {
            Tracker::record('save', $car->lot_id, $car->id);
        }

        return $car;
    }

    public function remove(User $user, string $ulid): void
    {
        $car = Vehicle::query()->withoutGlobalScope('lot')->where('vehicles.ulid', strtolower($ulid))->firstOrFail();
        $user->favourites()->detach($car->id);
    }
}
