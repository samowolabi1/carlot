<?php

namespace Database\Factories;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminRole;
use App\Domain\Legal\LegalDocuments;
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
            // Most tests are about something else: people have accepted the current Terms and Privacy Policy.
            'terms_version' => LegalDocuments::userVersion(),
            'terms_accepted_at' => now(),
        ];
    }

    /** Someone who hasn't accepted the current Terms and Privacy Policy (older account, or made by an admin or a seller). */
    public function withoutTerms(): static
    {
        return $this->state(['terms_version' => null, 'terms_accepted_at' => null]);
    }

    public function staff(): static
    {
        return $this->state(['role' => UserRole::Staff]);
    }

    public function admin(): static
    {
        return $this->state([
            'role' => UserRole::Admin,
            'admin_role' => AdminRole::Owner,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
        ]);
    }

    /** An admin with a particular role in the team. */
    public function adminAs(AdminRole $role): static
    {
        return $this->admin()->state(['admin_role' => $role]);
    }

    public function unnamed(): static
    {
        return $this->state(['name' => null]);
    }
}
