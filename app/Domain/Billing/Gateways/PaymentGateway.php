<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Models\Payment;

/**
 * The payment provider behind LotLink's own charges (TDD M16): Paystack in Nigeria; the
 * interface lets Stripe take over in other markets. Amounts are in minor units.
 */
interface PaymentGateway
{
    public function name(): string;

    /** Starts a checkout and returns the page to send the payer to. $planCode makes it recurring. */
    public function checkout(Payment $payment, string $email, string $callbackUrl, ?string $planCode = null): string;

    /** Asks the provider directly; never trust a redirect or webhook body on its own. */
    public function verify(string $reference): GatewayTransaction;

    /** Checks a webhook's signature against the raw body. */
    public function validWebhook(string $payload, ?string $signature): bool;

    /** Stops future renewals of a provider subscription. */
    public function cancelSubscription(string $subscriptionCode, string $token): void;

    /** A provider-hosted page where the owner can change the card, if there is one. */
    public function manageLink(string $subscriptionCode): ?string;

    public function refund(string $reference, ?int $amount = null): void;

    /** Creates a recurring plan at the provider; returns its code. */
    public function createPlan(string $name, int $amount, string $interval): string;

    /**
     * Changes a recurring plan's amount at the provider (minor units). $existing: current
     * subscribers pay it from their next renewal too; otherwise only new subscribers do.
     */
    public function updatePlan(string $code, int $amount, bool $existing): void;
}
