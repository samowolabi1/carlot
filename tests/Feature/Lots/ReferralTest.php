<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Actions\DowngradeToFree;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotReferral;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->referrerOwner = User::factory()->staff()->create();
    $this->referrer = app(CreateLot::class)->run($this->referrerOwner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->newOwner = User::factory()->create(['name' => 'Kemi Ade']);

    $this->signUp = function () {
        $this->actingAs($this->newOwner)->get(route('dealer.onboarding.start', ['ref' => strtolower($this->referrer->referral_code)]))->assertOk();
        $this->actingAs($this->newOwner)->post(route('dealer.lots.store'), ['name' => 'Victory Cars', 'phone' => '08021113344'])->assertSessionHasNoErrors();

        return Lot::where('name', 'Victory Cars')->sole();
    };
    $this->pay = function (Lot $lot) {
        $this->actingAs($this->newOwner)->post(route('dealer.billing.checkout', $lot), ['plan' => 'starter'], ['X-Inertia' => 'true']);
        $this->actingAs($this->newOwner)->get(route('dealer.billing.callback', ['lot' => $lot, 'reference' => $this->payments->lastReference()]));
    };
});

it('gives every seller a referral code and shows the invite page to the owner', function () {
    expect($this->referrer->referral_code)->toMatch('/^[A-Z0-9]{8}$/');

    $this->actingAs($this->referrerOwner)->get(route('dealer.referrals', $this->referrer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Referrals')
            ->where('code', $this->referrer->referral_code)
            ->where('link', route('dealer.onboarding.start', ['ref' => $this->referrer->referral_code])));
});

it('records the referral and adds a month to the referrer\'s trial when the new lot pays', function () {
    $trialEnds = Subscription::where('lot_id', $this->referrer->id)->sole()->trial_ends_at;
    $new = ($this->signUp)();

    expect(LotReferral::sole())->referred_lot_id->toBe($new->id)->status->toBe('signed_up');

    ($this->pay)($new);

    expect(LotReferral::sole())->status->toBe('rewarded')->reward_months->toBe(1)
        ->and(Subscription::where('lot_id', $this->referrer->id)->sole()->trial_ends_at->toIso8601String())->toBe($trialEnds->copy()->addMonth()->toIso8601String())
        ->and($this->referrerOwner->notifications()->latest()->first()->data['text'])->toContain('Victory Cars joined CarYard with your referral code');

    // Only once, even if they pay again.
    ($this->pay)($new);
    expect(Subscription::where('lot_id', $this->referrer->id)->sole()->trial_ends_at->toIso8601String())->toBe($trialEnds->copy()->addMonth()->toIso8601String());

    $this->actingAs($this->referrerOwner)->get(route('dealer.referrals', $this->referrer))
        ->assertInertia(fn (Assert $page) => $page->where('referrals.0.lot', 'Victory Cars')->where('referrals.0.rewarded', true));
});

it('gives a referrer on Free a month of Starter', function () {
    app(DowngradeToFree::class)->run($this->referrer, 'test');
    $new = ($this->signUp)();
    ($this->pay)($new);

    $sub = Subscription::where('lot_id', $this->referrer->id)->sole();
    expect($this->referrer->fresh()->plan_id)->toBe(Plan::where('code', 'starter')->value('id'))
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->current_period_end->toDateString())->toBe('2026-11-05')
        ->and($sub->cancel_at_period_end)->not->toBeNull();
});

it('ignores codes for the owner\'s own lots and unknown codes', function () {
    $this->actingAs($this->referrerOwner)->get(route('dealer.onboarding.start', ['ref' => $this->referrer->referral_code]));
    $this->actingAs($this->referrerOwner)->post(route('dealer.lots.store'), ['name' => 'Prime Motors Lekki', 'phone' => '08021115566']);

    $this->actingAs($this->newOwner)->get(route('dealer.onboarding.start', ['ref' => 'NOPE1234']));
    $this->actingAs($this->newOwner)->post(route('dealer.lots.store'), ['name' => 'Victory Cars', 'phone' => '08021113344']);

    expect(LotReferral::count())->toBe(0);
});

it('keeps the referral page to the owner', function () {
    $manager = User::factory()->staff()->create();
    $this->referrer->members()->attach($manager, ['role' => 'manager', 'accepted_at' => now()]);

    $this->actingAs($manager)->get(route('dealer.referrals', $this->referrer))->assertForbidden();
});
