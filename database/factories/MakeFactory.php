<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\Make;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Make>
 */
class MakeFactory extends Factory
{
    protected $model = Make::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->lastName().' Motors Co';

        return ['name' => $name, 'slug' => Str::slug($name)];
    }
}
