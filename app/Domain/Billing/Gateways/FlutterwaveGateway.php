<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Flutterwave's v3 API (https://developer.flutterwave.com). Amounts there are in major units
 * (naira), so they're converted at the edge; CarYard keeps kobo. Webhooks carry the secret hash
 * set in the dashboard (`verif-hash`), which is only a shared secret, so every charge is still
 * verified here before anything is delivered.
 */
class FlutterwaveGateway implements PaymentGateway
{
    public function __construct(private readonly string $secretKey, private readonly string $secretHash, private readonly string $baseUrl) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function checkout(Payment $payment, string $email, string $callbackUrl, ?string $planCode = null): string
    {
        $data = $this->send('post', '/payments', array_filter([
            'tx_ref' => $payment->reference,
            'amount' => self::major($payment->amount),
            'currency' => $payment->currency,
            'redirect_url' => $callbackUrl,
            'payment_plan' => $planCode,
            'customer' => ['email' => $email],
            'customizations' => ['title' => 'CarYard', 'description' => $payment->description, 'logo' => url('/icons/icon-192.png')],
            'meta' => ['payment' => $payment->ulid, 'purpose' => $payment->purpose->value],
        ], fn ($v) => $v !== null));

        return (string) $data['link'];
    }

    public function verify(string $reference): GatewayTransaction
    {
        $data = $this->send('get', '/transactions/verify_by_reference', ['tx_ref' => $reference]);

        return new GatewayTransaction(
            reference: (string) ($data['tx_ref'] ?? $reference),
            successful: ($data['status'] ?? null) === 'successful',
            amount: self::minor($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            customerCode: isset($data['customer']['id']) ? (string) $data['customer']['id'] : null,
            cardBrand: isset($data['card']['type']) ? strtolower((string) $data['card']['type']) : null,
            cardLast4: $data['card']['last_4digits'] ?? null,
            message: $data['processor_response'] ?? null,
            customerEmail: $data['customer']['email'] ?? null,
            providerId: isset($data['id']) ? (string) $data['id'] : null,
        );
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        return $signature !== null && $this->secretHash !== '' && hash_equals($this->secretHash, $signature);
    }

    public function cancelSubscription(string $subscriptionCode, string $token): void
    {
        $this->send('put', '/subscriptions/'.rawurlencode($subscriptionCode).'/cancel');
    }

    /** Flutterwave has no hosted card-update page: owners pay again with the new card. */
    public function manageLink(string $subscriptionCode): ?string
    {
        return null;
    }

    public function refund(string $reference, ?int $amount = null): void
    {
        $id = $this->verify($reference)->providerId ?? throw new RuntimeException('Flutterwave: transaction not found.');
        $this->send('post', '/transactions/'.rawurlencode($id).'/refund', array_filter(['amount' => $amount !== null ? self::major($amount) : null]));
    }

    public function createPlan(string $name, int $amount, string $interval): string
    {
        $data = $this->send('post', '/payment-plans', ['name' => $name, 'amount' => self::major($amount), 'interval' => $interval === 'month' ? 'monthly' : $interval]);

        return (string) $data['id'];
    }

    /**
     * Flutterwave can't change a plan's amount, so a new plan is made at the new price for new
     * subscribers (the old one keeps billing current subscribers). It can't move current subscribers.
     */
    public function updatePlan(string $code, int $amount, bool $existing): string
    {
        if ($existing) {
            throw new RuntimeException('Flutterwave can\'t change the price for current subscribers. Choose "Only sellers that subscribe from now on", or ask them to resubscribe.');
        }
        $old = $this->send('get', '/payment-plans/'.rawurlencode($code));

        return $this->createPlan((string) ($old['name'] ?? 'CarYard plan'), $amount, (string) ($old['interval'] ?? 'monthly'));
    }

    /** The subscription Flutterwave created for a plan payment (for cancelling it later). */
    public function subscriptionFor(GatewayTransaction $transaction, ?string $planCode): ?array
    {
        if ($planCode === null || $transaction->customerEmail === null) {
            return null;
        }
        $subscriptions = $this->send('get', '/subscriptions', ['email' => $transaction->customerEmail, 'status' => 'active']);
        $match = collect($subscriptions)->first(fn ($s) => (string) ($s['plan'] ?? '') === $planCode) ?? collect($subscriptions)->first();

        return isset($match['id']) ? ['ref' => (string) $match['id'], 'token' => 'flutterwave'] : null;
    }

    /** Kobo to naira for the API: whole naira as an integer, else two decimals. */
    public static function major(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : round($minor / 100, 2);
    }

    public static function minor(mixed $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    private function send(string $method, string $path, array $body = []): array
    {
        try {
            $response = $method === 'get' ? $this->http()->get($path, $body) : $this->http()->{$method}($path, $body);

            return (array) $response->throw()->json('data');
        } catch (RequestException $e) {
            throw new RuntimeException('Flutterwave: '.($e->response->json('message') ?? $e->getMessage()), previous: $e);
        }
    }

    private function http(): PendingRequest
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Set FLUTTERWAVE_SECRET_KEY to take payments with Flutterwave.');
        }

        return Http::baseUrl($this->baseUrl)->withToken($this->secretKey)->acceptJson()->timeout(20)->retry(2, 300, throw: false);
    }
}
