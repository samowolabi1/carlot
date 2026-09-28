<?php

namespace App\Domain\Inventory\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;

/**
 * Sales staff add and edit cars (product spec: "Add/edit cars"); prices of live cars,
 * status changes and deleting are for owners and managers ("Manager: stock, prices").
 */
class VehiclePolicy
{
    public function viewAny(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot);
    }

    public function create(User $user, Lot $lot): bool
    {
        return $user->hasLotRole($lot);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasLotRole($this->lot($vehicle));
    }

    /** Drafts are priced by whoever lists them; live prices belong to owners and managers. */
    public function changePrice(User $user, Vehicle $vehicle): bool
    {
        return $vehicle->listed_at === null
            ? $user->hasLotRole($this->lot($vehicle))
            : $user->hasLotRole($this->lot($vehicle), LotRole::Owner, LotRole::Manager);
    }

    public function publish(User $user, Vehicle $vehicle): bool
    {
        return $user->hasLotRole($this->lot($vehicle));
    }

    public function changeStatus(User $user, Vehicle $vehicle): bool
    {
        return $user->hasLotRole($this->lot($vehicle), LotRole::Owner, LotRole::Manager);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->hasLotRole($this->lot($vehicle), LotRole::Owner, LotRole::Manager);
    }

    private function lot(Vehicle $vehicle): Lot
    {
        $current = app(CurrentLot::class)->get();

        return $current !== null && $current->id === $vehicle->lot_id ? $current : Lot::findOrFail($vehicle->lot_id);
    }
}
