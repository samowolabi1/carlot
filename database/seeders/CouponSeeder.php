<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\Coupon;
use App\Domain\Lots\Models\Plan;
use Illuminate\Database\Seeder;

/** The launch offer from the product spec: the first lots get 3 months free on Starter. */
class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $starter = Plan::where('code', 'starter')->first();

        if ($starter !== null) {
            Coupon::firstOrCreate(['code' => 'LAUNCH3'], ['plan_id' => $starter->id, 'trial_days' => 90, 'max_redemptions' => 20]);
        }
    }
}
