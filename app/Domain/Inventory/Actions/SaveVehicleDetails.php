<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Sharing\Jobs\RenderShareCard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveVehicleDetails
{
    /** @param array<string, mixed> $data validated details, with feature_ids */
    public function run(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data): Vehicle {
            $vehicle->fill(Arr::except($data, ['feature_ids']))->save();
            $vehicle->features()->sync($data['feature_ids'] ?? []);
            RenderShareCard::refresh($vehicle->id);

            return $vehicle;
        });
    }
}
