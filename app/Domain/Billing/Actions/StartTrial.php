<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;

class StartTrial
{
    /** A new lot gets the default plan free for 14 days (TDD M16). */
    public function run(Lot $lot): ?Subscription
    {
        $plan = $lot->plan ?? Plan::default();

        if ($plan === null) {
            return null;
        }

        return Subscription::updateOrCreate(['lot_id' => $lot->id], [
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays((int) config('lotlink.billing.trial_days')),
        ]);
    }
}
