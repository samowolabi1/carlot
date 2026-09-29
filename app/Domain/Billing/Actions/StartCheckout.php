<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Support\BillingEmail;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Validation\ValidationException;

class StartCheckout
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Sends the owner to pay for a plan through the provider an admin chose. With that provider's
     * plan code the card is saved and charged each month; the plan only changes once the payment is verified (FulfilPayment).
     */
    public function run(Lot $lot, User $user, Plan $plan): string
    {
        if ($plan->isFree() || ! $plan->self_serve || $plan->price <= 0) {
            throw ValidationException::withMessages(['plan' => "The {$plan->name} plan can't be bought here."]);
        }

        $subscription = Subscription::firstOrCreate(['lot_id' => $lot->id], ['plan_id' => $plan->id, 'status' => 'trialing']);

        $payment = Payment::create([
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'user_id' => $user->id,
            'lot_id' => $lot->id,
            'purpose' => PaymentPurpose::Subscription,
            'description' => "{$plan->name} plan",
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'provider' => $this->gateway->name(),
            'reference' => Payment::newReference('sub'),
            'meta' => ['plan_id' => $plan->id],
        ]);

        return $this->gateway->checkout($payment, BillingEmail::for($lot), route('dealer.billing.callback', $lot), $plan->codeFor($this->gateway->name()));
    }
}
