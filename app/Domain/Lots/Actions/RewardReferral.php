<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotReferral;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\DB;

class RewardReferral
{
    public const MONTHS = 1;

    /**
     * A seller that signed up with a referral code paid for its first plan: the referrer gets a
     * month (TDD M19: RewardReferral). It is added to the end of their plan or trial; a seller
     * on Free gets a month of Starter.
     */
    public function run(Lot $referred): void
    {
        DB::transaction(function () use ($referred): void {
            $referral = LotReferral::lockForUpdate()->where('referred_lot_id', $referred->id)->where('status', 'signed_up')->first();
            $referrer = $referral ? Lot::find($referral->referrer_lot_id) : null;

            if ($referral === null || $referrer === null) {
                return;
            }

            $subscription = Subscription::lockForUpdate()->where('lot_id', $referrer->id)->first();
            $months = self::MONTHS;

            if ($subscription?->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null) {
                $subscription->forceFill(['trial_ends_at' => $subscription->trial_ends_at->copy()->addMonths($months)])->save();
            } elseif ($subscription !== null && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
                $from = $subscription->current_period_end?->isFuture() ? $subscription->current_period_end : now();
                $subscription->forceFill(['status' => SubscriptionStatus::Active, 'grace_ends_at' => null, 'current_period_end' => $from->copy()->addMonths($months)])->save();
            } elseif ($starter = Plan::where('code', 'starter')->first()) {
                // No paid plan: a free month of Starter that doesn't renew.
                Subscription::updateOrCreate(['lot_id' => $referrer->id], [
                    'plan_id' => $starter->id,
                    'status' => SubscriptionStatus::Active,
                    'current_period_end' => now()->addMonths($months),
                    'cancel_at_period_end' => now(),
                    'grace_ends_at' => null,
                ]);
                $referrer->forceFill(['plan_id' => $starter->id])->save();
            }

            $referral->forceFill(['status' => 'rewarded', 'reward_months' => $months, 'rewarded_at' => now()])->save();
            $referrer->owner?->notify(new BillingNotice($referrer, "{$referred->name} joined CarYard with your referral code and chose a plan. You get a free month. Thank you!"));
        });
    }
}
