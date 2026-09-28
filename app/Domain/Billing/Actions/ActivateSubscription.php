<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\GatewayTransaction;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Actions\RewardReferral;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Throwable;

class ActivateSubscription
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly RewardReferral $rewardReferral) {}

    /** A paid plan starts (or changes) now and runs for a month; Paystack renews it. */
    public function run(Payment $payment, GatewayTransaction $transaction): Subscription
    {
        $plan = Plan::findOrFail($payment->meta['plan_id'] ?? 0);
        $subscription = Subscription::lockForUpdate()->where('lot_id', $payment->lot_id)->firstOrFail();
        $previous = $subscription->replicate();

        // Changing plan: the old recurring charge stops; the new one arrives by webhook.
        if ($previous->provider_ref && $previous->provider_token && $previous->plan_id !== $plan->id) {
            try {
                $this->gateway->cancelSubscription($previous->provider_ref, $previous->provider_token);
            } catch (Throwable $e) {
                report($e);
            }
            $subscription->provider_ref = null;
            $subscription->provider_token = null;
        }

        $subscription->fill([
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'cancel_at_period_end' => null,
            'current_period_end' => now()->addMonth(),
            'customer_code' => $transaction->customerCode ?? $subscription->customer_code,
            'card_brand' => $transaction->cardBrand ?? $subscription->card_brand,
            'card_last4' => $transaction->cardLast4 ?? $subscription->card_last4,
        ])->save();

        $lot = Lot::findOrFail($payment->lot_id);
        $lot->forceFill(['plan_id' => $plan->id])->save();

        $lot->owner?->notify(new BillingNotice($lot, "Your {$plan->name} plan is active. Thank you!"));
        $this->rewardReferral->run($lot);

        return $subscription;
    }
}
