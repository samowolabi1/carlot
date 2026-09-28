<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    protected $model = VehicleModel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word()).' '.fake()->numberBetween(100, 900);

        return ['make_id' => Make::factory(), 'name' => $name, 'slug' => Str::slug($name), 'body_type' => 'sedan', 'approved_at' => now()];
    }
}
