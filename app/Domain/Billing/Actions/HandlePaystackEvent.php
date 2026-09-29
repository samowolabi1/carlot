<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use Illuminate\Support\Carbon;

/**
 * Paystack webhook events (TDD M16). Each stored event is handled once; payments are
 * re-verified with Paystack before anything is delivered.
 */
class HandlePaystackEvent
{
    public function __construct(private readonly FulfilPayment $fulfil, private readonly RecordRenewal $renewal) {}

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
        $subscription = $customer ? Subscription::with('plan')->where('customer_code', $customer)->where(fn ($q) => $q->where('provider', 'paystack')->orWhereNull('provider'))->first() : null;

        if ($subscription === null || empty($data['plan'])) {
            return;
        }

        $this->renewal->paid($subscription, 'paystack', $reference, (int) ($data['amount'] ?? 0), (string) ($data['currency'] ?? 'NGN'),
            isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null);
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
        if ($subscription !== null) {
            $this->renewal->failed($subscription, isset($data['amount']) ? (int) $data['amount'] : null);
        }
    }

    /** @param array<string, mixed> $data */
    private function renewalsStopped(array $data): void
    {
        $subscription = $this->bySubscriptionCode($data['subscription_code'] ?? null);
        if ($subscription !== null) {
            $this->renewal->stopped($subscription);
        }
    }

    private function bySubscriptionCode(?string $code): ?Subscription
    {
        return $code ? Subscription::where('provider_ref', $code)->where(fn ($q) => $q->where('provider', 'paystack')->orWhereNull('provider'))->first() : null;
    }
}
