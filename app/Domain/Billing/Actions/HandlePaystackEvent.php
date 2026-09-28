<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Paystack webhook events (TDD M16). Each stored event is handled once; payments are
 * re-verified with Paystack before anything is delivered.
 */
class HandlePaystackEvent
{
    public function __construct(private readonly FulfilPayment $fulfil) {}

    public function run(WebhookEvent $event): void
    {
        $data = $event->payload['data'] ?? [];

        match ($event->event) {
            'charge.success' => $this->chargeSucceeded($data),
            'subscription.create' => $this->subscriptionCreated($data),
            'invoice.payment_failed' => $this->renewalFailed($data),
            'subscription.not_renew', 'subscription.disable' => $this->renewalsStopped($data),
            default => null,
        };

        $event->forceFill(['processed_at' => now()])->save();
    }

    /** @param array<string, mixed> $data */
    private function chargeSucceeded(array $data): void
    {
        $reference = (string) ($data['reference'] ?? '');
        $payment = Payment::where('reference', $reference)->first();

        if ($payment !== null) {
            $this->fulfil->run($payment);

            return;
        }

        // A reference we didn't make: Paystack renewing a plan with the saved card.
        $customer = $data['customer']['customer_code'] ?? null;
        $subscription = $customer ? Subscription::with('plan')->where('customer_code', $customer)->first() : null;

        if ($subscription === null || empty($data['plan'])) {
            return;
        }

        Payment::create([
            'payable_type' => $subscription->getMorphClass(),
            'payable_id' => $subscription->id,
            'lot_id' => $subscription->lot_id,
            'purpose' => PaymentPurpose::Renewal,
            'description' => "{$subscription->plan->name} plan renewal",
            'amount' => (int) ($data['amount'] ?? 0),
            'currency' => (string) ($data['currency'] ?? 'NGN'),
            'provider' => 'paystack',
            'reference' => $reference,
            'status' => PaymentStatus::Success,
            'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
        ]);

        $from = $subscription->current_period_end !== null && $subscription->current_period_end->isFuture() ? $subscription->current_period_end : now();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_end' => $from->copy()->addMonth(),
            'grace_ends_at' => null,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function subscriptionCreated(array $data): void
    {
        $customer = $data['customer']['customer_code'] ?? null;
        $subscription = $customer ? Subscription::where('customer_code', $customer)->first() : null;

        $subscription?->forceFill([
            'provider_ref' => $data['subscription_code'] ?? null,
            'provider_token' => $data['email_token'] ?? null,
            'current_period_end' => isset($data['next_payment_date']) ? Carbon::parse($data['next_payment_date']) : $subscription->current_period_end,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function renewalFailed(array $data): void
    {
        $subscription = $this->bySubscriptionCode($data['subscription']['subscription_code'] ?? null);

        if ($subscription === null || $subscription->status === SubscriptionStatus::Cancelled) {
            return;
        }

        $grace = now()->addDays((int) config('lotlink.billing.grace_days'));
        $subscription->forceFill(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => $grace])->save();

        $lot = Lot::findOrFail($subscription->lot_id);
        $amount = isset($data['amount']) ? Money::format((int) $data['amount']) : 'your plan';
        $lot->owner?->notify(new BillingNotice($lot, "We couldn't charge your card for {$amount}. Update your card by {$grace->timezone($lot->timezone)->format('j M')} to keep all your cars live."));
    }

    /** @param array<string, mixed> $data */
    private function renewalsStopped(array $data): void
    {
        $subscription = $this->bySubscriptionCode($data['subscription_code'] ?? null);

        if ($subscription !== null && $subscription->status === SubscriptionStatus::Active && $subscription->cancel_at_period_end === null) {
            $subscription->forceFill(['cancel_at_period_end' => now()])->save();
        }
    }

    private function bySubscriptionCode(?string $code): ?Subscription
    {
        return $code ? Subscription::where('provider_ref', $code)->first() : null;
    }
}
