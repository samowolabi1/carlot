<?php

namespace Database\Seeders;

use App\Domain\Lots\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Plans from the product spec's monetisation table. Prices are placeholders (0) until
 * they are set after talking to the first lots.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'listing_limit' => 10, 'staff_limit' => 1, 'free_spotlights' => 0],
            ['code' => 'starter', 'name' => 'Starter', 'listing_limit' => 50, 'staff_limit' => 3, 'free_spotlights' => 0],
            ['code' => 'pro', 'name' => 'Pro', 'listing_limit' => null, 'staff_limit' => 10, 'free_spotlights' => 2],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'listing_limit' => null, 'staff_limit' => null, 'free_spotlights' => 2],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], [...$plan, 'price' => 0, 'currency' => config('lotlink.currency')]);
        }
    }
}
