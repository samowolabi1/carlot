<?php

namespace App\Domain\Inventory\Events;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;

/** Price-drop alerts for saved searches and favourites listen for this (sprint S13). */
class VehiclePriceDropped
{
    use Dispatchable;

    public function __construct(
        public readonly Vehicle $vehicle,
        public readonly int $oldPrice,
        public readonly int $newPrice,
    ) {}
}
