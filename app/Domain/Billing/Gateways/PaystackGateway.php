<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Paystack's REST API (https://paystack.com/docs/api). */
class PaystackGateway implements PaymentGateway
{
    public function __construct(private readonly string $secretKey, private readonly string $baseUrl) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function checkout(Payment $payment, string $email, string $callbackUrl, ?string $planCode = null): string
    {
        $data = $this->post('/transaction/initialize', array_filter([
            'email' => $email,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl,
            'plan' => $planCode,
            'channels' => $payment->meta['channels'] ?? null,
            'metadata' => ['payment' => $payment->ulid, 'purpose' => $payment->purpose->value],
        ], fn ($v) => $v !== null));

        return (string) $data['authorization_url'];
    }

    public function verify(string $reference): GatewayTransaction
    {
        $data = $this->http()->get('/transaction/verify/'.rawurlencode($reference))->throw()->json('data');

        return new GatewayTransaction(
            reference: (string) $data['reference'],
            successful: ($data['status'] ?? null) === 'success',
            amount: (int) $data['amount'],
            currency: (string) ($data['currency'] ?? 'NGN'),
            paidAt: isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null,
            customerCode: $data['customer']['customer_code'] ?? null,
            cardBrand: $data['authorization']['brand'] ?? $data['authorization']['card_type'] ?? null,
            cardLast4: $data['authorization']['last4'] ?? null,
            message: $data['gateway_response'] ?? null,
        );
    }

    public function validWebhook(string $payload, ?string $signature): bool
    {
        // x-paystack-signature is the HMAC SHA-512 of the raw body with the secret key.
        return $signature !== null && $this->secretKey !== '' && hash_equals(hash_hmac('sha512', $payload, $this->secretKey), $signature);
    }

    public function cancelSubscription(string $subscriptionCode, string $token): void
    {
        $this->post('/subscription/disable', ['code' => $subscriptionCode, 'token' => $token]);
    }

    public function manageLink(string $subscriptionCode): ?string
    {
        return $this->http()->get('/subscription/'.rawurlencode($subscriptionCode).'/manage/link')->throw()->json('data.link');
    }

    public function refund(string $reference, ?int $amount = null): void
    {
        $this->post('/refund', array_filter(['transaction' => $reference, 'amount' => $amount]));
    }

    public function createPlan(string $name, int $amount, string $interval): string
    {
        $data = $this->post('/plan', ['name' => $name, 'amount' => $amount, 'interval' => $interval === 'month' ? 'monthly' : $interval]);

        return (string) $data['plan_code'];
    }

    public function updatePlan(string $code, int $amount, bool $existing): string
    {
        try {
            $this->http()->put('/plan/'.rawurlencode($code), ['amount' => $amount, 'update_existing_subscriptions' => $existing])->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('Paystack: '.($e->response->json('message') ?? $e->getMessage()), previous: $e);
        }

        return $code;
    }

    /** Paystack sends the subscription in its subscription.create webhook. */
    public function subscriptionFor(GatewayTransaction $transaction, ?string $planCode): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            return (array) $this->http()->post($path, $body)->throw()->json('data');
        } catch (RequestException $e) {
            throw new RuntimeException('Paystack: '.($e->response->json('message') ?? $e->getMessage()), previous: $e);
        }
    }

    private function http(): PendingRequest
    {
        if ($this->secretKey === '') {
            throw new RuntimeException('Set PAYSTACK_SECRET_KEY to take payments.');
        }

        return Http::baseUrl($this->baseUrl)->withToken($this->secretKey)->acceptJson()->timeout(20)->retry(2, 300, throw: false);
    }
}
