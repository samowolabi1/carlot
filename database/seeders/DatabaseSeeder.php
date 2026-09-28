<?php

namespace Database\Seeders;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, CouponSeeder::class, VehicleCatalogueSeeder::class]);

        // Platform admin for the Filament panel at /admin.
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@lotlink.test')],
            [
                'name' => 'LotLink Admin',
                'phone' => env('ADMIN_PHONE', '+2348000000000'),
                'password' => env('ADMIN_PASSWORD', 'password'),
                'role' => UserRole::Admin,
                'phone_verified_at' => now(),
            ],
        );
    }
}
