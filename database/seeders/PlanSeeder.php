<?php

namespace Database\Seeders;

use App\Domain\Lots\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Plans from the product spec's monetisation table. Prices are placeholders until they
 * are set after talking to the first lots; change them in the admin (/admin/plans).
 * Re-running the seeder keeps prices and Paystack codes already set there.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // Lot Manager gating (TDD M19): Free lots keep up to 10 open orders. Offers and deposits are Pro (spec).
            ['code' => 'free', 'name' => 'Free', 'price' => 0, 'listing_limit' => 10, 'staff_limit' => 1, 'free_spotlights' => 0, 'sort' => 1,
                'features' => ['open_orders' => 10, 'share_cards' => false, 'offers' => false, 'deposits' => false, 'instalments' => false, 'daily_summary' => false, 'costs' => false, 'analytics' => false, 'analytics_full' => false, 'bulk_import' => false]],
            ['code' => 'starter', 'name' => 'Starter', 'price' => 15_000_00, 'listing_limit' => 50, 'staff_limit' => 3, 'free_spotlights' => 0, 'sort' => 2,
                'features' => ['share_cards' => true, 'offers' => false, 'deposits' => false, 'instalments' => true, 'daily_summary' => true, 'costs' => false, 'analytics' => true, 'analytics_full' => false, 'bulk_import' => false]],
            ['code' => 'pro', 'name' => 'Pro', 'price' => 45_000_00, 'listing_limit' => null, 'staff_limit' => 10, 'free_spotlights' => 2, 'sort' => 3,
                'features' => ['share_cards' => true, 'offers' => true, 'deposits' => true, 'instalments' => true, 'daily_summary' => true, 'costs' => true, 'analytics' => true, 'analytics_full' => true, 'bulk_import' => false]],
            ['code' => 'enterprise', 'name' => 'Enterprise', 'price' => 0, 'listing_limit' => null, 'staff_limit' => null, 'free_spotlights' => 2, 'sort' => 4, 'self_serve' => false,
                'features' => ['share_cards' => true, 'offers' => true, 'deposits' => true, 'instalments' => true, 'daily_summary' => true, 'costs' => true, 'analytics' => true, 'analytics_full' => true, 'bulk_import' => true]],
        ];

        foreach ($plans as $data) {
            $plan = Plan::firstOrNew(['code' => $data['code']]);

            if (! $plan->exists) {
                $plan->fill([...$data, 'currency' => config('lotlink.currency')]);
            } else {
                // Limits and features follow the spec; prices stay as the admin set them
                // (a price of 0 on a paid plan means it was never set).
                $plan->fill(collect($data)->except($plan->price > 0 ? ['price'] : [])->all());
            }

            $plan->save();
        }
    }
}
