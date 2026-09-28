<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaystackGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\WebhookEvent;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors']);
    $this->hook = function (array $body, ?string $signature = null) {
        $payload = json_encode($body);

        return $this->call('POST', '/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $payload, FakePaymentGateway::SECRET),
        ], $payload);
    };
});

it('rejects deliveries without a valid signature', function () {
    ($this->hook)(['event' => 'charge.success', 'data' => []], 'forged')->assertStatus(401);

    expect(WebhookEvent::count())->toBe(0);
});

it('fulfils a pending payment from charge.success, once', function () {
    $this->actingAs($this->owner)->post(route('dealer.billing.checkout', $this->lot), ['plan' => 'pro'], ['X-Inertia' => 'true']);
    $reference = $this->payments->lastReference();
    $body = ['event' => 'charge.success', 'data' => ['reference' => $reference, 'amount' => 1]]; // the body's amount is not trusted

    ($this->hook)($body)->assertOk();
    ($this->hook)($body)->assertOk()->assertSee('Already received');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Success)
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)
        ->and(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
});

it('records renewals and keeps the plan going', function () {
    Subscription::sole()->update([
        'status' => SubscriptionStatus::Active, 'plan_id' => Plan::where('code', 'pro')->value('id'),
        'customer_code' => 'CUS_abc', 'current_period_end' => now()->addDay(), 'trial_ends_at' => null,
    ]);

    ($this->hook)(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'customer' => ['customer_code' => 'CUS_abc']]])->assertOk();
    ($this->hook)(['event' => 'charge.success', 'data' => [
        'reference' => 'T_renewal_1', 'amount' => 4_500_000, 'currency' => 'NGN', 'paid_at' => now()->toIso8601String(),
        'plan' => ['plan_code' => 'PLN_pro'], 'customer' => ['customer_code' => 'CUS_abc'],
    ]])->assertOk();

    $subscription = Subscription::sole();
    expect($subscription->provider_ref)->toBe('SUB_1')
        ->and($subscription->provider_token)->toBe('tok_1')
        ->and($subscription->current_period_end->toDateString())->toBe('2026-11-06')
        ->and(Payment::sole()->purpose)->toBe(PaymentPurpose::Renewal)
        ->and(Payment::sole()->amount)->toBe(4_500_000);
});

it('puts a plan past due with 7 days of grace when a renewal fails', function () {
    Subscription::sole()->update(['status' => SubscriptionStatus::Active, 'provider_ref' => 'SUB_1', 'trial_ends_at' => null]);

    ($this->hook)(['event' => 'invoice.payment_failed', 'data' => ['amount' => 4_500_000, 'subscription' => ['subscription_code' => 'SUB_1']]])->assertOk();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue)
        ->and(Subscription::sole()->grace_ends_at->toDateString())->toBe('2026-10-12')
        ->and($this->whatsapp->to($this->owner->phone, 'billing_update')[0]->params[1])->toContain('₦45,000');
});

it('talks to the Paystack API as documented', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'r1']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'reference' => 'r1', 'status' => 'success', 'amount' => 4_500_000, 'currency' => 'NGN', 'paid_at' => '2026-10-05T09:00:00.000Z',
            'customer' => ['customer_code' => 'CUS_x'], 'authorization' => ['brand' => 'visa', 'last4' => '4081'],
        ]]),
    ]);
    $gateway = new PaystackGateway('sk_test_123', 'https://api.paystack.co');
    $payment = new Payment(['amount' => 4_500_000, 'currency' => 'NGN', 'reference' => 'r1', 'purpose' => 'subscription']);

    expect($gateway->checkout($payment, 'a@b.ng', 'https://lotlink.test/cb', 'PLN_pro'))->toBe('https://checkout.paystack.com/abc');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_123')
        && $request['plan'] === 'PLN_pro' && $request['amount'] === 4_500_000 && $request['reference'] === 'r1');

    $transaction = $gateway->verify('r1');
    expect($transaction->successful)->toBeTrue()
        ->and($transaction->amount)->toBe(4_500_000)
        ->and($transaction->cardLast4)->toBe('4081');

    $body = '{"event":"charge.success"}';
    expect($gateway->validWebhook($body, hash_hmac('sha512', $body, 'sk_test_123')))->toBeTrue()
        ->and($gateway->validWebhook($body, hash_hmac('sha512', $body, 'wrong')))->toBeFalse()
        ->and($gateway->validWebhook($body, null))->toBeFalse();
});
