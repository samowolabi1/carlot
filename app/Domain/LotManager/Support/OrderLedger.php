<?php

namespace App\Domain\LotManager\Support;

use App\Domain\Deals\Models\Reservation;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;

/**
 * Keeps an order's cached totals and payment status in step with its payments, and its
 * car's status in step with the order (TDD M19: Orders). Call with the order row locked.
 */
class OrderLedger
{
    public function __construct(private readonly VehicleStateMachine $stateMachine) {}

    public function recalculate(SalesOrder $order): void
    {
        $paid = (int) OrderPayment::query()->where('sales_order_id', $order->id)->whereNull('voided_at')->sum('amount');

        $order->total_paid = $paid;
        $order->balance = $order->total() - $paid;

        // Payments move an open order along the flow; a voided payment can move it back.
        // papers_ready is set by hand and is kept.
        if (in_array($order->status, [OrderStatus::Draft, OrderStatus::DepositPaid, OrderStatus::FullyPaid], true)) {
            $order->status = match (true) {
                $paid > 0 && $order->balance <= 0 => OrderStatus::FullyPaid,
                $paid > 0 => OrderStatus::DepositPaid,
                default => OrderStatus::Draft,
            };
        }

        $order->save();
        InstalmentSchedule::apply($order);
        $this->syncVehicle($order);
    }

    /** Past draft the car is reserved; delivered sells it; draft or cancelled frees it. */
    public function syncVehicle(SalesOrder $order): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->lockForUpdate()->find($order->vehicle_id);

        if ($vehicle === null || $vehicle->status === VehicleStatus::Sold) {
            return;
        }

        $target = match ($order->status) {
            OrderStatus::Delivered => VehicleStatus::Sold,
            OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady => VehicleStatus::Reserved,
            default => $this->heldByAnother($order) ? null : VehicleStatus::Available,
        };

        if ($target === null || $target === $vehicle->status) {
            return;
        }

        // A draft or cancelled order only frees a car it had reserved.
        if ($target === VehicleStatus::Available && $vehicle->status !== VehicleStatus::Reserved) {
            return;
        }

        if ($target === VehicleStatus::Reserved && $vehicle->status !== VehicleStatus::Available) {
            return;
        }

        if ($target === VehicleStatus::Sold && ! $this->stateMachine->canTransition($vehicle->status, $target)) {
            // A car hidden while its order was open comes back to be sold.
            $this->stateMachine->transition($vehicle, VehicleStatus::Available);
        }

        $this->stateMachine->transition($vehicle, $target);
    }

    private function heldByAnother(SalesOrder $order): bool
    {
        // A paid reservation keeps the car held until it ends (TDD M12).
        if (Reservation::activeFor($order->vehicle_id) !== null) {
            return true;
        }

        return SalesOrder::withoutGlobalScopes()
            ->where('vehicle_id', $order->vehicle_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', [OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady])
            ->exists();
    }
}
