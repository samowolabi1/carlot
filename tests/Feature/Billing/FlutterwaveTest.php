<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Actions\ChangePlanPrice;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use App\Filament\Pages\PaymentSettings;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->admin = User::factory()->admin()->create();
    $this->owner = User::factory()->staff()->create(['email' => 'ada@primemotors.ng']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->pro = Plan::where('code', 'pro')->first();
    $this->pro->update(['provider_plan_code' => 'PLN_pro', 'flutterwave_plan_id' => '4501']);

    $this->checkout = fn () => $this->actingAs($this->owner)->post(route('dealer.billing.checkout', $this->lot), ['plan' => 'pro'], ['X-Inertia' => 'true']);
    // Flutterwave sends people back with ?status=…&tx_ref=…&transaction_id=…
    $this->callback = fn (string $ref) => $this->actingAs($this->owner)->get(route('dealer.billing.callback', ['lot' => $this->lot, 'status' => 'successful', 'tx_ref' => $ref, 'transaction_id' => 77]));
    $this->webhook = fn (array $body, ?string $hash = FakePaymentGateway::HASH) => $this->call('POST', '/webhooks/flutterwave', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => $hash,
    ], json_encode($body));
});

it('lets an admin choose the provider for new payments', function () {
    expect(PaymentGateways::activeProvider())->toBe('paystack');

    Livewire::actingAs($this->admin)->test(PaymentSettings::class)
        ->assertFormSet(['provider' => 'paystack'])
        ->fillForm(['provider' => 'flutterwave'])->call('save')->assertNotified('New payments go through Flutterwave');

    expect(PaymentGateways::activeProvider())->toBe('flutterwave')
        ->and(AuditLog::where('action', 'admin.payment_provider_changed')->sole()->changes)->toEqual(['before' => 'paystack', 'after' => 'flutterwave']);

    $this->actingAs($this->owner)->get('/admin/settings/payments')->assertForbidden();
});

it('refuses a provider whose keys aren\'t set, once payments are live', function () {
    config(['lotlink.billing.driver' => 'live', 'services.paystack.secret_key' => 'sk_live', 'services.flutterwave.secret_key' => null]);

    expect(PaymentGateways::configured('paystack'))->toBeTrue()->and(PaymentGateways::configured('flutterwave'))->toBeFalse();
    Livewire::actingAs($this->admin)->test(PaymentSettings::class)->fillForm(['provider' => 'flutterwave'])->call('save');
    expect(PaymentGateways::activeProvider())->toBe('paystack');
});

it('takes a plan payment through Flutterwave and renews through it', function () {
    PaymentGateways::choose('flutterwave');
    $this->flutterwave->subscription = ['ref' => '881', 'token' => 'flutterwave'];

    ($this->checkout)()->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.flutterwave.test/'.$this->flutterwave->lastReference());
    expect($this->flutterwave->checkouts[0])->toMatchArray(['email' => 'ada@primemotors.ng', 'plan' => '4501'])
        ->and($this->payments->checkouts)->toBe([])
        ->and(Payment::sole()->provider)->toBe('flutterwave');

    ($this->callback)($this->flutterwave->lastReference())->assertSessionHas('success', 'Paid. Your plan is active.');

    $subscription = Subscription::sole();
    expect($subscription)->provider->toBe('flutterwave')->provider_ref->toBe('881')->customer_code->toBe('CUS_test')
        ->status->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_end->toDateString())->toBe('2026-11-05');

    // Next month Flutterwave charges the card and tells us; we check with Flutterwave before recording it.
    $this->travelTo('2026-11-05 08:00');
    ($this->webhook)(['event' => 'charge.completed', 'data' => ['tx_ref' => 'flw-renew-1', 'status' => 'successful', 'customer' => ['id' => 'CUS_test'], 'amount' => 45000]])->assertOk();

    $renewal = Payment::where('purpose', PaymentPurpose::Renewal)->sole();
    expect($renewal)->provider->toBe('flutterwave')->reference->toBe('flw-renew-1')->status->toBe(PaymentStatus::Success)
        ->and($subscription->fresh()->current_period_end->toDateString())->toBe('2026-12-05');

    // The same delivery again changes nothing.
    ($this->webhook)(['event' => 'charge.completed', 'data' => ['tx_ref' => 'flw-renew-1', 'status' => 'successful', 'customer' => ['id' => 'CUS_test'], 'amount' => 45000]])->assertOk();
    expect(Payment::where('purpose', PaymentPurpose::Renewal)->count())->toBe(1);

    // A failed renewal (as Flutterwave's API reports it) starts the grace period.
    $this->flutterwave->results['flw-renew-2'] = ['successful' => false];
    ($this->webhook)(['event' => 'charge.completed', 'data' => ['tx_ref' => 'flw-renew-2', 'status' => 'failed', 'customer' => ['id' => 'CUS_test']]]);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);

    // Cancelled at Flutterwave: runs to the end of the month.
    $subscription->fresh()->forceFill(['status' => SubscriptionStatus::Active])->save();
    ($this->webhook)(['event' => 'subscription.cancelled', 'data' => ['id' => 881]]);
    expect($subscription->fresh()->cancel_at_period_end)->not->toBeNull();
});

it('rejects Flutterwave webhooks without the secret hash, and never trusts the body', function () {
    ($this->webhook)(['event' => 'charge.completed', 'data' => []], 'wrong')->assertUnauthorized();
    ($this->webhook)(['event' => 'charge.completed', 'data' => []], null)->assertUnauthorized();

    // A "successful" charge for our payment that Flutterwave's API says failed: not paid.
    PaymentGateways::choose('flutterwave');
    ($this->checkout)();
    $ref = $this->flutterwave->lastReference();
    $this->flutterwave->results[$ref] = ['successful' => false];
    ($this->webhook)(['event' => 'charge.completed', 'data' => ['tx_ref' => $ref, 'status' => 'successful']])->assertOk();
    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and($this->lot->fresh()->plan->code)->not->toBe('pro');
});

it('keeps old payments and subscriptions with the provider that took them', function () {
    // Paid through Paystack…
    ($this->checkout)();
    ($this->callback)($this->payments->lastReference());
    $subscription = Subscription::sole();
    $subscription->forceFill(['provider_ref' => 'SUB_paystack', 'provider_token' => 'tok'])->save();
    expect($subscription->provider)->toBe('paystack');

    // …then the admin switches to Flutterwave.
    PaymentGateways::choose('flutterwave');

    app(RefundPayment::class)->run(Payment::sole(), $this->admin);
    expect($this->payments->refunded)->toHaveCount(1)->and($this->flutterwave->refunded)->toBe([]);

    $this->actingAs($this->owner)->post(route('dealer.billing.cancel', $this->lot));
    expect($this->payments->cancelled)->toBe(['SUB_paystack'])->and($this->flutterwave->cancelled)->toBe([]);
});

it('stops the Paystack renewal when a seller resubscribes through Flutterwave', function () {
    ($this->checkout)();
    ($this->callback)($this->payments->lastReference());
    Subscription::sole()->forceFill(['provider_ref' => 'SUB_paystack', 'provider_token' => 'tok'])->save();

    PaymentGateways::choose('flutterwave');
    $this->flutterwave->subscription = ['ref' => '990', 'token' => 'flutterwave'];
    ($this->checkout)();
    ($this->callback)($this->flutterwave->lastReference());

    expect($this->payments->cancelled)->toBe(['SUB_paystack'])
        ->and(Subscription::sole())->provider->toBe('flutterwave')->provider_ref->toBe('990');
});

it('changes plan prices at both providers (a new Flutterwave plan for new subscribers)', function () {
    app(ChangePlanPrice::class)->run($this->pro, 50000, false, $this->admin);

    expect($this->payments->updatedPlans)->toBe([['code' => 'PLN_pro', 'amount' => 5_000_000, 'existing' => false]])
        ->and($this->flutterwave->updatedPlans[0])->toMatchArray(['code' => '4501', 'existing' => false])
        ->and($this->pro->fresh())->price->toBe(5_000_000)->provider_plan_code->toBe('PLN_pro')->flutterwave_plan_id->toBe('9001');

    // "Everyone" can't reach Flutterwave subscribers: refused, nothing changes.
    Subscription::sole()->forceFill(['plan_id' => $this->pro->id, 'status' => SubscriptionStatus::Active, 'provider' => 'flutterwave'])->save();
    expect(fn () => app(ChangePlanPrice::class)->run($this->pro->fresh(), 55000, true, $this->admin))
        ->toThrow(ValidationException::class, 'Flutterwave didn\'t accept the change');
    expect($this->pro->fresh()->price)->toBe(5_000_000);
});

it('creates a plan on Flutterwave from the admin', function () {
    $this->pro->update(['flutterwave_plan_id' => null]);

    Livewire::actingAs($this->admin)->test(ListPlans::class)
        ->assertTableColumnStateSet('flutterwave_plan_id', null, $this->pro)
        ->callTableAction('create_flutterwave', $this->pro)
        ->assertNotified('Plan created on Flutterwave');

    expect($this->pro->fresh()->flutterwave_plan_id)->toBe('9001')
        ->and($this->flutterwave->createdPlans[0])->toBe(['name' => 'CarYard Pro', 'amount' => $this->pro->price]);
});
