<?php

namespace Tests\Support;

use App\Domain\Billing\Gateways\GatewayTransaction;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;

/** Paystack (or Flutterwave) stand-in for tests: payments succeed at the asked amount unless told otherwise. */
class FakePaymentGateway implements PaymentGateway
{
    public const SECRET = 'sk_test_fake';

    /** Flutterwave's webhook secret hash in tests. */
    public const HASH = 'flw_test_hash';

    /** @var list<array{name: string, amount: int}> */
    public array $createdPlans = [];

    /** @var array{ref: string, token: string}|null what subscriptionFor() finds */
    public ?array $subscription = null;

    public function __construct(private readonly string $provider = 'paystack') {}

    /** @var list<array{reference: string, email: string, plan: ?string, callback: string}> */
    public array $checkouts = [];

    /** @var array<string, array{successful?: bool, amount?: int}> */
    public array $results = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<string> */
    public array $refunded = [];

    public function name(): string
    {
        return $this->provider;
    }

    public function checkout(Payment $payment, string $email, string $callbackUrl, ?string $planCode = null): string
    {
        $this->checkouts[] = ['reference' => $payment->reference, 'email' => $email, 'plan' => $planCode, 'callback' => $callbackUrl];

        return "https://checkout.{$this->provider}.test/".$payment->reference;
    }

    public function verify(string $reference): GatewayTransaction
    {
        $payment = Payment::where('reference', $reference)->first();
        $result = $this->results[$reference] ?? [];

        return new GatewayTransaction(
            reference: $reference,
            successful: $result['successful'] ?? true,
            amount: $result['amount'] ?? (int) $payment?->amount,
            currency: 'NGN',
            paidAt: now(),
            customerCode: 'CUS_test',
            cardBrand: 'visa',
            cardLast4: '7731',
            message: ($result['successful'] ?? true) ? null : 'Declined',
        );
    }

    public function lastReference(): string
    {
        return end($this->checkouts)['reference'];
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        if ($this->provider === 'flutterwave') {
            return $signature !== null && hash_equals(self::HASH, $signature);
        }

        return $signature !== null && hash_equals(hash_hmac('sha512', $payload, self::SECRET), $signature);
    }

    public function cancelSubscription(string $subscriptionCode, string $token): void
    {
        $this->cancelled[] = $subscriptionCode;
    }

    public function manageLink(string $subscriptionCode): ?string
    {
        return 'https://paystack.test/manage/'.$subscriptionCode;
    }

    public function refund(string $reference, ?int $amount = null): void
    {
        $this->refunded[] = $reference;
    }

    public function createPlan(string $name, int $amount, string $interval): string
    {
        $this->createdPlans[] = ['name' => $name, 'amount' => $amount];

        return $this->provider === 'flutterwave' ? (string) (9000 + count($this->createdPlans)) : 'PLN_test';
    }

    /** @var list<array{code: string, amount: int, existing: bool}> */
    public array $updatedPlans = [];

    public bool $failPlanUpdate = false;

    public function updatePlan(string $code, int $amount, bool $existing): string
    {
        if ($this->failPlanUpdate) {
            throw new \RuntimeException('Paystack: plan not found');
        }
        $this->updatedPlans[] = ['code' => $code, 'amount' => $amount, 'existing' => $existing];

        if ($this->provider === 'flutterwave') {
            // Like Flutterwave: no repricing, a new plan for new subscribers.
            if ($existing) {
                throw new \RuntimeException('Flutterwave can\'t change the price for current subscribers.');
            }

            return $this->createPlan('CarYard plan', $amount, 'monthly');
        }

        return $code;
    }

    public function subscriptionFor(GatewayTransaction $transaction, ?string $planCode): ?array
    {
        return $this->subscription;
    }
}
