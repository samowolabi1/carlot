<?php

namespace Database\Seeders;

use App\Domain\Admin\Actions\EnsureFirstAdmin;
use App\Domain\Messaging\MessageCatalogue;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, CouponSeeder::class, VehicleCatalogueSeeder::class]);
        MessageCatalogue::sync();

        $this->firstAdmin();

        // On a local install (Laragon), the demo lenders too, so the lender portal can be tried straight away
        // (lender@lotlink.test / password). Never in production.
        if (app()->isLocal()) {
            $this->call(DemoLenderSeeder::class);
        }
    }

    /** Platform admin for the Filament panel at /admin, from config('lotlink.first_admin') (ADMIN_* in .env). */
    private function firstAdmin(): void
    {
        $result = app(EnsureFirstAdmin::class)->run();
        $result['status'] === 'skipped' ? $this->command?->warn($result['message']) : $this->command?->info($result['message']);
    }
}
