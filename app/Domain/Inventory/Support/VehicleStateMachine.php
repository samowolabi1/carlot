<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Exceptions\InvalidVehicleTransition;
use App\Domain\Inventory\Models\Vehicle;

/**
 * draft → available → reserved → sold, plus hidden. Every status change goes through
 * here; invalid moves throw.
 */
class VehicleStateMachine
{
    /** @var array<string, list<VehicleStatus>> */
    private const TRANSITIONS = [
        'draft' => [VehicleStatus::Available],
        'available' => [VehicleStatus::Reserved, VehicleStatus::Hidden, VehicleStatus::Sold],
        'reserved' => [VehicleStatus::Available, VehicleStatus::Sold],
        'hidden' => [VehicleStatus::Available],
        'sold' => [],
    ];

    public function canTransition(VehicleStatus $from, VehicleStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /** @return list<VehicleStatus> */
    public function allowedFrom(VehicleStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }

    public function transition(Vehicle $vehicle, VehicleStatus $to): void
    {
        if (! $this->canTransition($vehicle->status, $to)) {
            throw InvalidVehicleTransition::between($vehicle->status, $to);
        }

        $vehicle->status = $to;

        if ($to === VehicleStatus::Available) {
            // listed_at is kept when a hidden or reserved car comes back, so it isn't
            // shown as a new arrival again.
            $vehicle->listed_at ??= now();
        }

        if ($to === VehicleStatus::Sold) {
            $vehicle->sold_at = now();
        }

        $vehicle->save();
    }
}
