<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Gateways\FlutterwaveGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Lots\Actions\CreateLot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->gateway = new FlutterwaveGateway('FLWSECK_TEST-x', 'my-secret-hash', 'https://api.flutterwave.com/v3');
    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors']);
    $this->payment = Payment::create([
        'lot_id' => $lot->id, 'purpose' => PaymentPurpose::Subscription, 'description' => 'Pro plan',
        'amount' => 4_500_050, 'currency' => 'NGN', 'provider' => 'flutterwave', 'reference' => 'sub_ABC123',
    ]);
});

it('starts a checkout in naira with the plan and our reference', function () {
    Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/xyz']])]);

    $link = $this->gateway->checkout($this->payment, 'ada@primemotors.ng', 'https://lotlink.test/callback', '4501');

    expect($link)->toBe('https://checkout.flutterwave.com/pay/xyz');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-x')
        && $r['tx_ref'] === 'sub_ABC123' && $r['amount'] === 45000.5 && $r['currency'] === 'NGN'
        && $r['payment_plan'] === '4501' && $r['customer'] === ['email' => 'ada@primemotors.ng'] && $r['redirect_url'] === 'https://lotlink.test/callback');
});

it('verifies by reference and converts back to kobo', function () {
    Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => [
        'id' => 288200, 'tx_ref' => 'sub_ABC123', 'status' => 'successful', 'amount' => 45000.5, 'currency' => 'NGN',
        'created_at' => '2026-10-05T09:01:00.000Z', 'customer' => ['id' => 99, 'email' => 'ada@primemotors.ng'],
        'card' => ['type' => 'VISA', 'last_4digits' => '4081'], 'processor_response' => 'Approved',
    ]])]);

    $t = $this->gateway->verify('sub_ABC123');

    expect($t)->successful->toBeTrue()->amount->toBe(4_500_050)->customerCode->toBe('99')
        ->cardBrand->toBe('visa')->cardLast4->toBe('4081')->customerEmail->toBe('ada@primemotors.ng')->providerId->toBe('288200');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'tx_ref=sub_ABC123'));
});

it('checks the webhook secret hash', function () {
    expect($this->gateway->validWebhook('{}', 'my-secret-hash'))->toBeTrue()
        ->and($this->gateway->validWebhook('{}', 'nope'))->toBeFalse()
        ->and($this->gateway->validWebhook('{}', null))->toBeFalse()
        ->and((new FlutterwaveGateway('k', '', 'https://x'))->validWebhook('{}', ''))->toBeFalse();
});

it('refunds by transaction id and makes a new plan to change a price', function () {
    Http::fake([
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['data' => ['id' => 288200, 'tx_ref' => 'sub_ABC123', 'status' => 'successful', 'amount' => 45000, 'currency' => 'NGN']]),
        'api.flutterwave.com/v3/transactions/288200/refund' => Http::response(['status' => 'success', 'data' => ['id' => 1]]),
        'api.flutterwave.com/v3/payment-plans/4501' => Http::response(['data' => ['id' => 4501, 'name' => 'CarYard Pro', 'interval' => 'monthly']]),
        'api.flutterwave.com/v3/payment-plans' => Http::response(['data' => ['id' => 4600]]),
    ]);

    $this->gateway->refund('sub_ABC123', 4_500_000);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/transactions/288200/refund' && $r['amount'] === 45000);

    expect($this->gateway->updatePlan('4501', 5_000_000, false))->toBe('4600');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/payment-plans') && $r['amount'] === 50000 && $r['name'] === 'CarYard Pro');

    expect(fn () => $this->gateway->updatePlan('4501', 5_000_000, true))->toThrow(RuntimeException::class, 'current subscribers');
});

it('says what went wrong when Flutterwave refuses', function () {
    Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'error', 'message' => 'Invalid authorization key'], 401)]);

    expect(fn () => $this->gateway->checkout($this->payment, 'a@b.ng', 'https://x'))->toThrow(RuntimeException::class, 'Flutterwave: Invalid authorization key');
});
