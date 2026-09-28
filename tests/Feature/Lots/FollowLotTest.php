<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Models\Lot;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors']);
    $this->buyer = User::factory()->create(['phone' => '+2348035550101']);
});

it('follows and unfollows a lot', function () {
    $this->post(route('lots.follow', $this->lot))->assertRedirect(route('login'));

    $this->actingAs($this->buyer)->post(route('lots.follow', $this->lot))->assertSessionHas('success');
    $this->actingAs($this->buyer)->post(route('lots.follow', $this->lot));
    $this->actingAs($this->buyer)->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('following', true)->where('followers', 1));
    $this->actingAs($this->buyer)->get(route('following'))->assertInertia(fn (Assert $page) => $page->has('lots', 1));

    $this->actingAs($this->buyer)->delete(route('lots.unfollow', $this->lot));
    expect($this->lot->followers()->count())->toBe(0);

    $pending = Lot::factory()->create();
    $this->actingAs($this->buyer)->post(route('lots.follow', $pending))->assertNotFound();
});

it('tells followers about new stock in one message per lot', function () {
    $this->lot->followers()->attach($this->buyer);
    $this->artisan('followers:notify');

    $this->travel(10)->minutes();
    $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'listed_at' => now()]);
    $this->car($this->lot, 'Honda', 'Accord', ['year' => 2016, 'listed_at' => now()]);
    $this->travel(20)->minutes();
    $this->artisan('followers:notify')->assertSuccessful();
    $this->artisan('followers:notify')->assertSuccessful();

    $sent = $this->whatsapp->to('+2348035550101', 'lot_new_stock');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params[0])->toBe('Prime Motors')
        ->and($sent[0]->params[1])->toBe('2')
        ->and($this->buyer->notifications()->count())->toBe(1);
});

it('does not tell people who followed after the car was listed', function () {
    $this->artisan('followers:notify');
    $this->car($this->lot, 'Toyota', 'Camry', ['listed_at' => now()]);
    $this->travel(5)->minutes();
    $this->lot->followers()->attach($this->buyer);

    $this->artisan('followers:notify');

    expect($this->whatsapp->to('+2348035550101'))->toBe([]);
});
