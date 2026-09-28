<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Models\Payment;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A stand-in for Paystack while developing: checkout is a LotLink page with "Pay" and
 * "Decline" buttons, and verification reads what was chosen there. It goes through the
 * same fulfilment code as real payments. Never used in production.
 */
class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function checkout(Payment $payment, string $email, string $callbackUrl, ?string $planCode = null): string
    {
        $payment->forceFill(['meta' => [...($payment->meta ?? []), 'callback' => $callbackUrl]])->save();

        return URL::signedRoute('billing.sandbox', ['payment' => $payment->ulid]);
    }

    public function verify(string $reference): GatewayTransaction
    {
        $payment = Payment::where('reference', $reference)->firstOrFail();
        $result = $payment->meta['sandbox'] ?? null;

        return new GatewayTransaction(
            reference: $reference,
            successful: $result === 'paid',
            amount: $payment->amount,
            currency: $payment->currency,
            paidAt: $result === 'paid' ? now() : null,
            customerCode: 'CUS_sandbox_'.$payment->lot_id,
            cardBrand: 'visa',
            cardLast4: '4081',
            message: $result === 'declined' ? 'Declined in the sandbox' : null,
        );
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        return false;
    }

    public function cancelSubscription(string $subscriptionCode, string $token): void {}

    public function manageLink(string $subscriptionCode): ?string
    {
        return null;
    }

    public function refund(string $reference, ?int $amount = null): void {}

    public function createPlan(string $name, int $amount, string $interval): string
    {
        throw new RuntimeException('The sandbox has no plans to create. Set PAYMENT_DRIVER=paystack.');
    }
}
