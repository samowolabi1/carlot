<?php

namespace Database\Factories;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+234803'.fake()->unique()->numerify('#######'),
            'email' => null,
            'phone_verified_at' => now(),
            'role' => UserRole::Customer,
        ];
    }

    public function staff(): static
    {
        return $this->state(['role' => UserRole::Staff]);
    }

    public function admin(): static
    {
        return $this->state([
            'role' => UserRole::Admin,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);
    }

    public function unnamed(): static
    {
        return $this->state(['name' => null]);
    }
}
