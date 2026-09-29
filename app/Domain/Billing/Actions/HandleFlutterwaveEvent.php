<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;

/**
 * Flutterwave webhook events. Its webhooks only carry a shared secret hash, so a charge is always
 * verified with Flutterwave's API before anything happens: our own payments through `FulfilPayment`,
 * and renewals (a reference we didn't make) before they're recorded.
 */
class HandleFlutterwaveEvent
{
    public function __construct(private readonly FulfilPayment $fulfil, private readonly RecordRenewal $renewal, private readonly PaymentGateways $gateways) {}

    public function run(WebhookEvent $event): void
    {
        $data = $event->payload['data'] ?? [];

        match ($event->event) {
            'charge.completed' => $this->charge($data),
            'subscription.cancelled' => $this->cancelled($data),
            default => null,
        };

        $event->forceFill(['processed_at' => now()])->save();
    }

    /** @param array<string, mixed> $data */
    private function charge(array $data): void
    {
        $reference = (string) ($data['tx_ref'] ?? '');
        if ($reference === '') {
            return;
        }

        $payment = Payment::where('reference', $reference)->first();
        if ($payment !== null) {
            $this->fulfil->run($payment);

            return;
        }

        // Flutterwave charging a subscriber's saved card for the next month.
        $subscription = $this->subscription(isset($data['customer']['id']) ? (string) $data['customer']['id'] : null);
        if ($subscription === null) {
            return;
        }

        $transaction = $this->gateways->for('flutterwave')->verify($reference);
        if ($transaction->successful) {
            $this->renewal->paid($subscription, 'flutterwave', $reference, $transaction->amount, $transaction->currency, $transaction->paidAt);
        } else {
            $this->renewal->failed($subscription, $transaction->amount ?: null);
        }
    }

    /** @param array<string, mixed> $data */
    private function cancelled(array $data): void
    {
        $id = (string) ($data['id'] ?? '');
        $subscription = $id !== '' ? Subscription::where('provider', 'flutterwave')->where('provider_ref', $id)->first() : null;
        if ($subscription !== null) {
            $this->renewal->stopped($subscription);
        }
    }

    private function subscription(?string $customer): ?Subscription
    {
        return $customer ? Subscription::with('plan')->where('provider', 'flutterwave')->where('customer_code', $customer)->first() : null;
    }
}
