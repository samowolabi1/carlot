<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyCoupon
{
    /**
     * A code like the launch offer (3 months free on Starter) restarts the trial on its
     * plan. Only for lots that haven't paid yet, once per lot.
     */
    public function run(Lot $lot, string $code): Subscription
    {
        return DB::transaction(function () use ($lot, $code): Subscription {
            $coupon = Coupon::where('code', strtoupper(trim($code)))->lockForUpdate()->first();

            if ($coupon === null || ! $coupon->isUsable()) {
                throw ValidationException::withMessages(['coupon' => 'That code is not valid or has been used up.']);
            }

            $subscription = Subscription::lockForUpdate()->firstOrNew(['lot_id' => $lot->id]);

            $paid = Payment::where('lot_id', $lot->id)->where('purpose', PaymentPurpose::Subscription)->where('status', PaymentStatus::Success)->exists();
            if ($paid || $subscription->status === SubscriptionStatus::Active) {
                throw ValidationException::withMessages(['coupon' => 'Codes are for sellers that have not paid for a plan yet.']);
            }

            if ($subscription->coupon_id !== null) {
                throw ValidationException::withMessages(['coupon' => 'This seller has already used a code.']);
            }

            $subscription->fill([
                'plan_id' => $coupon->plan_id,
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => now()->addDays($coupon->trial_days),
                'grace_ends_at' => null,
                'coupon_id' => $coupon->id,
                'trial_reminded_at' => null,
            ])->save();

            $lot->forceFill(['plan_id' => $coupon->plan_id])->save();
            $coupon->increment('redeemed');

            return $subscription;
        });
    }
}
