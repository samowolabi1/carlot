<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lot>
 */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Motors';

        return [
            'owner_id' => User::factory()->staff(),
            'name' => $name,
            'slug' => Str::slug($name),
            'phone' => '+234802'.fake()->numerify('#######'),
            'whatsapp' => '+234802'.fake()->numerify('#######'),
            'address' => fake()->streetAddress(),
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'latitude' => 6.6018,
            'longitude' => 3.3515,
            'status' => LotStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => LotStatus::Active, 'submitted_at' => now(), 'verified_at' => now()]);
    }

    /** Attach the owner as a lot member, as CreateLot does. */
    public function configure(): static
    {
        return $this->afterCreating(function (Lot $lot): void {
            $lot->members()->syncWithoutDetaching([
                $lot->owner_id => ['role' => LotRole::Owner->value, 'accepted_at' => now()],
            ]);
        });
    }
}
