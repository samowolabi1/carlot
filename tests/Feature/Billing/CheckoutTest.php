<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\SandboxGateway;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->owner = User::factory()->staff()->create(['email' => 'ada@primemotors.ng']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->pro = Plan::where('code', 'pro')->first();
    $this->pro->update(['provider_plan_code' => 'PLN_pro']);
    $this->checkout = fn (string $plan = 'pro', ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->post(route('dealer.billing.checkout', $this->lot), ['plan' => $plan], ['X-Inertia' => 'true']);
    $this->callback = fn (?string $reference = null) => $this->actingAs($this->owner)
        ->get(route('dealer.billing.callback', ['lot' => $this->lot, 'reference' => $reference ?? $this->payments->lastReference()]));
});

it('starts every new lot on a 14-day Starter trial', function () {
    $subscription = Subscription::sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->code)->toBe('starter')
        ->and($subscription->trial_ends_at->toDateString())->toBe('2026-10-19');

    $this->actingAs($this->owner)->get(route('dealer.billing', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Billing')
            ->where('subscription.status', 'trialing')
            ->where('subscription.trial_days_left', 14)
            ->has('plans', 4)
            ->where('can.manage', true));
});

it('sends the owner to Paystack with a recurring plan and activates it once verified', function () {
    ($this->checkout)()->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.paystack.test/'.$this->payments->lastReference());

    expect($this->payments->checkouts[0])->toMatchArray(['email' => 'ada@primemotors.ng', 'plan' => 'PLN_pro'])
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Pending)
        ->and($this->lot->fresh()->plan->code)->toBe('starter'); // nothing changes before payment

    ($this->callback)()->assertRedirect(route('dealer.billing', $this->lot))->assertSessionHas('success', 'Paid. Your plan is active.');

    $subscription = Subscription::sole();
    expect(Payment::sole()->status)->toBe(PaymentStatus::Success)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan->code)->toBe('pro')
        ->and($subscription->current_period_end->toDateString())->toBe('2026-11-05')
        ->and($subscription->card_last4)->toBe('7731')
        ->and($this->lot->fresh()->plan->code)->toBe('pro')
        ->and($this->whatsapp->to($this->owner->phone, 'billing_update'))->toHaveCount(1);
});

it('fulfils a payment once however often the owner comes back', function () {
    ($this->checkout)();
    ($this->callback)();
    ($this->callback)();

    expect(Payment::count())->toBe(1)
        ->and($this->whatsapp->to($this->owner->phone, 'billing_update'))->toHaveCount(1);
});

it('keeps the plan when the payment fails or the amount is short', function (array $result, string $reason) {
    ($this->checkout)();
    $this->payments->results[$this->payments->lastReference()] = $result;

    ($this->callback)()->assertSessionHas('error');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Failed)
        ->and(Payment::sole()->meta['reason'])->toBe($reason)
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($this->lot->fresh()->plan->code)->toBe('starter');
})->with([
    'declined' => [['successful' => false], 'Declined'],
    'short' => [['amount' => 100], 'Amount did not match'],
]);

it('only lets the owner change the plan, and not to plans that are not for sale', function () {
    $manager = User::factory()->staff()->create();
    $this->lot->members()->attach($manager, ['role' => LotRole::Manager->value, 'accepted_at' => now()]);

    ($this->checkout)('pro', $manager)->assertForbidden();
    $this->actingAs($manager)->get(route('dealer.billing', $this->lot))->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

    ($this->checkout)('enterprise')->assertSessionHasErrors('plan');
    ($this->checkout)('free')->assertSessionHasErrors('plan');
    expect(Payment::count())->toBe(0);
});

it('stops the old recurring charge when switching plans', function () {
    ($this->checkout)('starter');
    ($this->callback)();
    Subscription::sole()->update(['provider_ref' => 'SUB_starter', 'provider_token' => 'tok']);

    ($this->checkout)('pro');
    ($this->callback)();

    expect($this->payments->cancelled)->toBe(['SUB_starter'])
        ->and(Subscription::sole()->plan->code)->toBe('pro')
        ->and(Subscription::sole()->provider_ref)->toBeNull();
});

it('cancels at the end of the paid month, or straight away during a trial', function () {
    ($this->checkout)();
    ($this->callback)();
    Subscription::sole()->update(['provider_ref' => 'SUB_pro', 'provider_token' => 'tok']);

    $this->actingAs($this->owner)->post(route('dealer.billing.cancel', $this->lot))->assertSessionHas('success', 'Your plan will not renew. It stays active until 5 Nov.');
    expect($this->payments->cancelled)->toBe(['SUB_pro'])
        ->and(Subscription::sole()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->lot->fresh()->plan->code)->toBe('pro');

    $other = app(CreateLot::class)->run($owner = User::factory()->staff()->create(), ['name' => 'Autoworld']);
    $this->actingAs($owner)->post(route('dealer.billing.cancel', $other))->assertSessionHas('success', "You're on the Free plan now.");
    expect($other->fresh()->plan->code)->toBe('free');
});

it('applies the launch code once, only before paying', function () {
    Coupon::create(['code' => 'launch3', 'plan_id' => Plan::where('code', 'starter')->value('id'), 'trial_days' => 90, 'max_redemptions' => 1]);

    $this->actingAs($this->owner)->post(route('dealer.billing.coupon', $this->lot), ['coupon' => 'LAUNCH3 '])->assertSessionHasNoErrors();
    expect(Subscription::sole()->trial_ends_at->toDateString())->toBe('2027-01-03')
        ->and(Coupon::sole()->redeemed)->toBe(1);

    $this->actingAs($this->owner)->post(route('dealer.billing.coupon', $this->lot), ['coupon' => 'launch3'])->assertSessionHasErrors('coupon');

    $other = app(CreateLot::class)->run($owner = User::factory()->staff()->create(), ['name' => 'Autoworld']);
    $this->actingAs($owner)->post(route('dealer.billing.coupon', $other), ['coupon' => 'launch3'])
        ->assertSessionHasErrors(['coupon' => 'That code is not valid or has been used up.']);
});

it('serves invoices for the lot\'s own payments only', function () {
    ($this->checkout)();
    ($this->callback)();
    $payment = Payment::sole();

    $response = $this->actingAs($this->owner)->get(route('dealer.billing.invoice', [$this->lot, $payment]));
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $other = app(CreateLot::class)->run($owner = User::factory()->staff()->create(), ['name' => 'Autoworld']);
    $this->actingAs($owner)->get(route('dealer.billing.invoice', [$other, $payment]))->assertNotFound();
    $this->actingAs($owner)->get(route('dealer.billing', $this->lot))->assertForbidden();
});

it('runs the sandbox checkout end to end without Paystack', function () {
    $this->app->instance(PaymentGateway::class, new SandboxGateway);

    $location = ($this->checkout)('pro')->headers->get('X-Inertia-Location');
    $this->get($location)->assertOk()->assertSee('Test mode');

    $payment = Payment::sole();
    $this->actingAs($this->owner)->post(URL::signedRoute('billing.sandbox.complete', ['payment' => $payment->ulid]), ['result' => 'paid'])
        ->assertRedirect(route('dealer.billing.callback', ['lot' => $this->lot, 'reference' => $payment->reference]));

    ($this->callback)($payment->reference)->assertSessionHas('success');
    expect(Subscription::sole()->plan->code)->toBe('pro');
});
