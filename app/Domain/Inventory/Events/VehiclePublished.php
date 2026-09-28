<?php

namespace App\Domain\Inventory\Events;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Foundation\Events\Dispatchable;

/** Search indexing, share cards and follower alerts hook in here in later sprints. */
class VehiclePublished
{
    use Dispatchable;

    public function __construct(public readonly Vehicle $vehicle) {}
}
