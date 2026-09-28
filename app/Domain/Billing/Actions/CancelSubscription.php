<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class CancelSubscription
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly DowngradeToFree $downgrade) {}

    /**
     * Choosing the Free plan. A paid plan keeps running to the end of the month already
     * paid for, then moves to Free; a trial moves to Free straight away.
     */
    public function run(Lot $lot, User $user): Subscription
    {
        $subscription = Subscription::where('lot_id', $lot->id)->first()
            ?? throw ValidationException::withMessages(['plan' => 'You are already on the Free plan.']);

        if ($subscription->status === SubscriptionStatus::Active) {
            if ($subscription->provider_ref && $subscription->provider_token) {
                $this->gateway->cancelSubscription($subscription->provider_ref, $subscription->provider_token);
            }
            $subscription->forceFill(['cancel_at_period_end' => now()])->save();
            AuditLog::record('billing.cancelled', $lot, ['ends' => $subscription->current_period_end?->toIso8601String()], $user, $lot->id);

            return $subscription;
        }

        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw ValidationException::withMessages(['plan' => 'You are already on the Free plan.']);
        }

        $this->downgrade->run($lot, 'you chose it');

        return $subscription->refresh();
    }
}
