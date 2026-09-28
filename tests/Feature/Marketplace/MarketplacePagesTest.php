<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors', 'city' => 'Ikeja', 'whatsapp' => '+2348021112233', 'phone' => '+2348021112233']);
    $this->camry = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'trim' => 'SE', 'price' => 1_250_000_000, 'vin' => '4T1B11HK8JU654821']);
});

it('renders the home page with new arrivals', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Home')->has('arrivals', 1)->where('carCount', 1)->where('lotCount', 1));
});

it('renders search results', function () {
    $this->get(route('cars.index', ['q' => 'camry']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Marketplace/Search')
            ->has('results.data', 1)
            ->where('results.data.0.title', '2018 Toyota Camry SE')
            ->where('results.data.0.price', '₦12,500,000')
            ->where('filters.q', 'camry'));
});

it('shows a car with share-preview tags rendered on the server', function () {
    $response = $this->get($this->camry->publicPath())->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Marketplace/Car')
        ->where('car.title', '2018 Toyota Camry SE')
        ->where('lot.name', 'Prime Motors')
        ->where('lot.whatsapp', '2348021112233')
        ->where('sold', false));

    $response->assertSee('<meta property="og:title" content="2018 Toyota Camry SE — ₦12,500,000">', false)
        ->assertSee('<meta property="og:image" content="', false)
        ->assertSee('<link rel="canonical" href="'.url($this->camry->publicPath()).'">', false);
});

it('only shows the last four characters of the VIN', function () {
    $this->get($this->camry->publicPath())
        ->assertDontSee('4T1B11HK8JU654821')
        ->assertInertia(fn (Assert $page) => $page->where('car.specs', fn ($specs) => collect($specs)->contains(['label' => 'VIN', 'value' => '···4821'])));
});

it('redirects to the canonical car URL', function () {
    $this->get('/car/'.$this->camry->ulid.'-old-slug')->assertRedirect($this->camry->publicPath());
    $this->get('/car/'.strtoupper($this->camry->ulid))->assertRedirect($this->camry->publicPath());
});

it('hides drafts and cars at unapproved lots from the public, but lets the lot preview them', function () {
    $draft = Vehicle::factory()->create(['lot_id' => $this->lot->id]);
    $pendingLot = Lot::factory()->create();
    $early = $this->car($pendingLot, 'Honda', 'Accord');

    $this->get($draft->publicPath())->assertNotFound();
    $this->get($early->publicPath())->assertNotFound();

    $this->actingAs($this->lot->owner)->get($draft->publicPath())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('preview', 'This is a draft only your team can see.'))
        ->assertSee('<meta name="robots" content="noindex">', false);
});

it('keeps sold cars reachable, marked sold and not indexed', function () {
    $this->camry->forceFill(['status' => VehicleStatus::Sold, 'sold_at' => now()])->save();

    $this->get($this->camry->publicPath())
        ->assertInertia(fn (Assert $page) => $page->where('sold', true))
        ->assertSee('<meta name="robots" content="noindex">', false);
});

it('compares cars and marks the best values', function () {
    $older = $this->car($this->lot, 'Toyota', 'Corolla', ['year' => 2015, 'price' => 790_000_000, 'mileage_km' => 150_000]);
    $hidden = $this->car($this->lot, 'Honda', 'Pilot');
    $hidden->forceFill(['status' => 'hidden'])->save();

    $this->get(route('compare', ['ids' => implode(',', [$this->camry->ulid, $older->ulid, $hidden->ulid])]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cars', 2)
            ->where('cars.0.rows.year.best', true)
            ->where('cars.1.rows.price.best', true)
            ->where('cars.0.rows.price.best', false));
});

it('serves the lot mini-site with its own stock only', function () {
    $other = Lot::factory()->active()->create();
    $this->car($other, 'Honda', 'Accord');

    $this->get(route('lots.show', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Marketplace/Lot')
            ->where('lot.name', 'Prime Motors')
            ->where('total', 1)
            ->has('stock.data', 1)
            ->where('preview', false))
        ->assertSee('<meta property="og:title" content="Prime Motors — cars for sale in Ikeja">', false);
});

it('keeps an unapproved lot\'s mini-site private to its team', function () {
    $pending = Lot::factory()->create();

    $this->get(route('lots.show', $pending))->assertNotFound();
    $this->actingAs($pending->owner)->get(route('lots.show', $pending))->assertInertia(fn (Assert $page) => $page->where('preview', true));
});

it('saves and unsaves cars', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer)->post(route('favourites.store', $this->camry->ulid))->assertRedirect();
    expect($buyer->favourites()->pluck('vehicles.id')->all())->toBe([$this->camry->id]);

    $this->actingAs($buyer)->get(route('saved'))->assertInertia(fn (Assert $page) => $page->has('cars', 1));

    $this->actingAs($buyer)->delete(route('favourites.destroy', $this->camry->ulid));
    expect($buyer->favourites()->count())->toBe(0);
});

it('asks guests to sign in and saves the car when they come back', function () {
    $this->get(route('favourites.remember', $this->camry->ulid))->assertRedirect(route('login'));

    $buyer = User::factory()->create();
    $this->actingAs($buyer)->get(route('favourites.remember', $this->camry->ulid))->assertRedirect($this->camry->publicPath());

    expect($buyer->favourites()->count())->toBe(1);
});

it('shows a price drop on saved cars', function () {
    $buyer = User::factory()->create();
    $this->actingAs($buyer)->post(route('favourites.store', $this->camry->ulid));
    $this->camry->update(['price' => 1_200_000_000]);

    $this->actingAs($buyer)->get(route('saved'))->assertInertia(fn (Assert $page) => $page
        ->where('cars.0.price_drop', '₦500,000')
        ->where('cars.0.old_price', '₦12,500,000'));
});

it('will not save a car that is not on the marketplace', function () {
    $draft = Vehicle::factory()->create(['lot_id' => $this->lot->id]);

    $this->actingAs(User::factory()->create())->post(route('favourites.store', $draft->ulid))->assertNotFound();
});
