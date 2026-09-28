<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use Illuminate\Validation\ValidationException;

class ChangeVehicleStatus
{
    public function __construct(
        private readonly VehicleStateMachine $stateMachine,
        private readonly PublishVehicle $publish,
    ) {}

    /**
     * Manual status changes from the stock list. Going live runs the publish checks;
     * selling goes through a Lot Manager order (sprint S5), not here.
     */
    public function run(Vehicle $vehicle, VehicleStatus $to): Vehicle
    {
        if ($to === VehicleStatus::Sold) {
            throw ValidationException::withMessages(['status' => 'Record the sale in Lot Manager to mark a car sold.']);
        }

        if (! $this->stateMachine->canTransition($vehicle->status, $to)) {
            throw ValidationException::withMessages(['status' => "A {$vehicle->status->value} car can't be marked {$to->value}."]);
        }

        if ($to === VehicleStatus::Available && $vehicle->status !== VehicleStatus::Reserved) {
            return $this->publish->run($vehicle);
        }

        $this->stateMachine->transition($vehicle, $to);

        return $vehicle;
    }
}
