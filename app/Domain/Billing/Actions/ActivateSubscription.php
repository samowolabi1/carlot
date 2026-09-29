<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\GatewayTransaction;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Actions\RewardReferral;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Throwable;

class ActivateSubscription
{
    public function __construct(private readonly PaymentGateways $gateways, private readonly RewardReferral $rewardReferral) {}

    /** A paid plan starts (or changes) now and runs for a month; the provider that took the payment renews it. */
    public function run(Payment $payment, GatewayTransaction $transaction): Subscription
    {
        $plan = Plan::findOrFail($payment->meta['plan_id'] ?? 0);
        $subscription = Subscription::lockForUpdate()->where('lot_id', $payment->lot_id)->firstOrFail();
        $previous = $subscription->replicate();

        // Changing plan (or provider): the old recurring charge stops; the new one arrives by webhook
        // (Paystack) or is looked up below (Flutterwave).
        if ($previous->provider_ref && $previous->provider_token && ($previous->plan_id !== $plan->id || $previous->provider !== $payment->provider)) {
            try {
                $this->gateways->for($previous->provider)->cancelSubscription($previous->provider_ref, $previous->provider_token);
            } catch (Throwable $e) {
                report($e);
            }
            $subscription->provider_ref = null;
            $subscription->provider_token = null;
        }

        $subscription->fill([
            'plan_id' => $plan->id,
            'provider' => $payment->provider,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'cancel_at_period_end' => null,
            'current_period_end' => now()->addMonth(),
            'customer_code' => $transaction->customerCode ?? $subscription->customer_code,
            'card_brand' => $transaction->cardBrand ?? $subscription->card_brand,
            'card_last4' => $transaction->cardLast4 ?? $subscription->card_last4,
        ])->save();

        try {
            $found = $this->gateways->for($payment->provider)->subscriptionFor($transaction, $plan->codeFor($payment->provider));
            if ($found !== null) {
                $subscription->forceFill(['provider_ref' => $found['ref'], 'provider_token' => $found['token']])->save();
            }
        } catch (Throwable $e) {
            report($e); // the plan is paid for; the renewal link can be found again later
        }

        $lot = Lot::findOrFail($payment->lot_id);
        $lot->forceFill(['plan_id' => $plan->id])->save();

        $lot->owner?->notify(new BillingNotice($lot, "Your {$plan->name} plan is active. Thank you!"));
        $this->rewardReferral->run($lot);

        return $subscription;
    }
}
