<?php

namespace Database\Factories;

use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lot_id' => Lot::factory(),
            // Attributes resolve in order: the model is created first, then its make is read.
            'vehicle_model_id' => VehicleModel::factory(),
            'make_id' => fn (array $attributes) => VehicleModel::find($attributes['vehicle_model_id'])->make_id,
            'year' => fake()->numberBetween(2008, 2022),
            'mileage_km' => fake()->numberBetween(20_000, 180_000),
            'price' => fake()->numberBetween(3_000, 40_000) * 1000 * 100,
            'currency' => 'NGN',
            'condition' => VehicleCondition::ForeignUsed,
            'transmission' => Transmission::Automatic,
            'fuel' => FuelType::Petrol,
        ];
    }

    public function available(): static
    {
        return $this->state(['listed_at' => now()->subDays(3)])->afterCreating(function (Vehicle $vehicle): void {
            $vehicle->forceFill(['status' => VehicleStatus::Available])->save();
        });
    }

    public function withPhoto(): static
    {
        return $this->afterCreating(function (Vehicle $vehicle): void {
            $media = new VehicleMedia(['status' => MediaStatus::Ready, 'is_cover' => true, 'sort_order' => 0]);
            $media->vehicle_id = $vehicle->id;
            $media->save();
            $media->update([
                'path' => VehicleMedia::variantPath($vehicle->ulid, $media->ulid, 1600),
                'thumb_path' => VehicleMedia::variantPath($vehicle->ulid, $media->ulid, 400),
            ]);
        });
    }
}
