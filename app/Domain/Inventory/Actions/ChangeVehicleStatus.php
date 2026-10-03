<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use Illuminate\Validation\ValidationException;

class ChangeVehicleStatus
{
    public function __construct(
        private readonly VehicleStateMachine $stateMachine,
        private readonly PublishVehicle $publish,
    ) {}

    /**
     * Manual status changes from the stock list. Going live runs the publish checks;
     * selling goes through a Sales Manager order, not here.
     */
    public function run(Vehicle $vehicle, VehicleStatus $to): Vehicle
    {
        if ($to === VehicleStatus::Sold) {
            throw ValidationException::withMessages(['status' => 'Record the sale in Sales Manager to mark a car sold.']);
        }

        if (! $this->stateMachine->canTransition($vehicle->status, $to)) {
            throw ValidationException::withMessages(['status' => "A {$vehicle->status->value} car can't be marked {$to->value}."]);
        }

        if ($vehicle->status === VehicleStatus::Reserved && $to === VehicleStatus::Available) {
            $order = SalesOrder::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)
                ->whereIn('status', [OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady])
                ->value('order_no');

            if ($order !== null) {
                throw ValidationException::withMessages(['status' => "Order {$order} holds this car. Cancel the order in Sales Manager to release it."]);
            }
        }

        if ($to === VehicleStatus::Available && $vehicle->status !== VehicleStatus::Reserved) {
            return $this->publish->run($vehicle);
        }

        $this->stateMachine->transition($vehicle, $to);

        return $vehicle;
    }
}
