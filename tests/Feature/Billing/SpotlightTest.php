<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors']);
    $this->lot->update(['status' => 'active']);
    $this->camry = $this->car($this->lot, 'Toyota', 'Camry', ['price' => 1_250_000_000]);
    $this->accord = $this->car($this->lot, 'Honda', 'Accord', ['price' => 900_000_000]);
    $this->buy = fn (Vehicle $car, array $data = ['days' => 7], ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->post(route('dealer.vehicles.spotlight', [$this->lot, $car]), $data, ['X-Inertia' => 'true']);
    $this->pay = fn () => $this->actingAs($this->owner)->get(route('dealer.billing.callback', ['lot' => $this->lot, 'reference' => $this->payments->lastReference()]));
});

it('spotlights a car once paid: first in search and on the home page', function () {
    ($this->buy)($this->camry, ['days' => 14])->assertStatus(409);

    $payment = Payment::sole();
    expect($payment->amount)->toBe(900_000)
        ->and($payment->description)->toContain('Camry')
        ->and($this->camry->fresh()->spotlight_until)->toBeNull();

    ($this->pay)()->assertRedirect(route('dealer.vehicles.index', $this->lot))->assertSessionHas('success', 'Paid. Your spotlight is live.');

    expect($this->camry->fresh()->spotlight_until->toDateString())->toBe('2026-10-19');

    $this->get(route('cars.index'))->assertInertia(fn (Assert $page) => $page
        ->has('sponsored', 1)
        ->where('sponsored.0.ulid', $this->camry->ulid)
        ->where('sponsored.0.sponsored', true));
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('spotlight', 1)->where('spotlight.0.ulid', $this->camry->ulid));
});

it('adds a second spotlight to the end of the first', function () {
    ($this->buy)($this->camry);
    ($this->pay)();
    ($this->buy)($this->camry, ['days' => 30]);
    ($this->pay)();

    expect(Spotlight::withoutGlobalScopes()->latest('id')->first()->starts_at->toDateString())->toBe('2026-10-12')
        ->and($this->camry->fresh()->spotlight_until->toDateString())->toBe('2026-11-11');
});

it('gives Pro lots two free 7-day spotlights a month', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'pro')->value('id')]);

    ($this->buy)($this->camry, ['days' => 7, 'free' => true])->assertSessionHas('success');
    ($this->buy)($this->accord, ['days' => 7, 'free' => true])->assertSessionHas('success');
    ($this->buy)($this->camry, ['days' => 7, 'free' => true])->assertSessionHasErrors(['days' => 'No free spotlights left this month.']);
    ($this->buy)($this->camry, ['days' => 14, 'free' => true])->assertSessionHasErrors('days');

    expect(Payment::count())->toBe(0)
        ->and($this->accord->fresh()->spotlight_until->toDateString())->toBe('2026-10-12');

    $this->travelTo('2026-11-01 09:00');
    ($this->buy)($this->camry, ['days' => 7, 'free' => true])->assertSessionHasNoErrors();
});

it('only spotlights the seller\'s own live cars, for owners and managers', function () {
    $theirs = $this->car(app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Autoworld']), 'Kia', 'Rio');
    ($this->buy)($theirs)->assertNotFound();

    $this->accord->forceFill(['status' => 'hidden'])->save();
    ($this->buy)($this->accord)->assertSessionHasErrors('days');

    $rep = User::factory()->staff()->create();
    $this->lot->members()->attach($rep, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    ($this->buy)($this->camry, ['days' => 7], $rep)->assertForbidden();

    ($this->buy)($this->camry, ['days' => 9])->assertSessionHasErrors('days');
    expect(Spotlight::withoutGlobalScopes()->count())->toBe(0);
});

it('features the seller on the home page', function () {
    $this->actingAs($this->owner)->post(route('dealer.spotlight.featured', $this->lot), ['days' => 7], ['X-Inertia' => 'true'])->assertStatus(409);
    ($this->pay)();

    expect($this->lot->fresh()->featured_until->toDateString())->toBe('2026-10-12');
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('featuredLots', 1)->where('featuredLots.0.name', 'Prime Motors')->where('featuredLots.0.cars', 2));
});

it('ends spotlights on time and after a refund', function () {
    ($this->buy)($this->camry);
    ($this->pay)();

    app(RefundPayment::class)->run(Payment::sole(), User::factory()->admin()->create());
    expect($this->camry->fresh()->spotlight_until)->toBeNull()
        ->and($this->payments->refunded)->toHaveCount(1);

    ($this->buy)($this->accord);
    ($this->pay)();
    $this->travelTo('2026-10-13 09:00');
    $this->artisan('spotlights:expire')->assertSuccessful();

    expect($this->accord->fresh()->spotlight_until)->toBeNull();
    $this->get(route('cars.index'))->assertInertia(fn (Assert $page) => $page->has('sponsored', 0));
});
