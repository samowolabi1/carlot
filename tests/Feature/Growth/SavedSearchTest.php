<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Marketplace\Models\SavedSearch;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'city' => 'Ikeja']);
    $this->lot->update(['status' => 'active', 'city' => 'Ikeja']);
    $this->existing = $this->car($this->lot, 'Toyota', 'RAV4', ['year' => 2017, 'price' => 1_450_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777']);

    // A draft RAV4 the seller will publish.
    $this->draft = function (int $naira = 14_000_000): Vehicle {
        $v = Vehicle::factory()->withPhoto()->create([
            'lot_id' => $this->lot->id, 'make_id' => $this->existing->make_id, 'vehicle_model_id' => $this->existing->vehicle_model_id,
            'year' => 2018, 'price' => $naira * 100, 'mileage_km' => 60000, 'condition' => 'foreign_used', 'transmission' => 'automatic', 'fuel' => 'petrol',
        ]);

        return $v->refresh();
    };
    $this->save = fn (array $filters, ?User $as = null) => $this->actingAs($as ?? $this->buyer)->post(route('saved-searches.store'), ['filters' => $filters, 'channel' => 'phone']);
});

it('saves a search with a readable name, never the buyer\'s location', function () {
    ($this->save)(['make' => [$this->existing->make_id], 'price_max' => 15000000, 'lat' => 6.6, 'lng' => 3.35, 'radius' => 10, 'sort' => 'nearest'])
        ->assertSessionHas('success');

    $saved = SavedSearch::sole();
    expect($saved->name)->toBe('Toyota cars under ₦15m')
        ->and($saved->filters)->toBe(['make' => [$this->existing->make_id], 'price_max' => 15000000]);

    // Saving the same filters again doesn't duplicate, and the search page shows it's saved.
    ($this->save)(['price_max' => 15000000, 'make' => [$this->existing->make_id]]);
    expect(SavedSearch::count())->toBe(1);
    $this->actingAs($this->buyer)->get(route('cars.index', ['make' => [$this->existing->make_id], 'price_max' => 15000000]))
        ->assertInertia(fn (Assert $page) => $page->where('savedSearch', $saved->ulid));

    ($this->save)(['sort' => 'price_asc'])->assertSessionHasErrors('filters');
    $this->actingAs($this->buyer)->get(route('saved'))->assertInertia(fn (Assert $page) => $page->has('searches', 1)->where('searches.0.name', 'Toyota cars under ₦15m'));
});

it('alerts matching saved searches when a car is published, at most every 6 hours', function () {
    ($this->save)(['make' => [$this->existing->make_id], 'price_max' => 15000000]);
    ($this->save)(['price_max' => 5000000], User::factory()->create(['phone' => '+2348035550778'])); // doesn't match
    ($this->save)(['make' => [$this->existing->make_id]], $this->owner); // the seller's own team

    app(PublishVehicle::class)->run(($this->draft)());

    expect($this->whatsapp->to('+2348035550777', 'saved_search_match'))->toHaveCount(1)
        ->and($this->whatsapp->to('+2348035550778', 'saved_search_match'))->toHaveCount(0)
        ->and($this->whatsapp->to('+2348020000001', 'saved_search_match'))->toHaveCount(0)
        ->and($this->buyer->notifications()->where('data->kind', 'alert')->sole()->data['text'])->toContain('New match for "Toyota cars under ₦15m": 2018 Toyota RAV4');

    app(PublishVehicle::class)->run(($this->draft)(13_000_000));
    expect($this->whatsapp->to('+2348035550777', 'saved_search_match'))->toHaveCount(1);

    $this->travel(7)->hours();
    app(PublishVehicle::class)->run(($this->draft)(12_000_000));
    expect($this->whatsapp->to('+2348035550777', 'saved_search_match'))->toHaveCount(2);
});

it('tells buyers who saved a car when its price drops', function () {
    $this->actingAs($this->buyer)->post(route('favourites.store', $this->existing->ulid));

    app(SaveVehiclePrice::class)->run($this->existing, $this->owner, 1_420_000_000, true);

    $sent = $this->whatsapp->to('+2348035550777', 'price_drop');
    expect($sent)->toHaveCount(1)->and($sent[0]->params)->toBe(['2017 Toyota RAV4', '₦14,200,000', '₦300,000', 'Prime Motors'])
        ->and($this->buyer->notifications()->where('data->kind', 'price_drop')->sole()->data['text'])->toContain('(₦300,000 less)');

    // A rise says nothing.
    app(SaveVehiclePrice::class)->run($this->existing->fresh(), $this->owner, 1_500_000_000, true);
    expect($this->whatsapp->to('+2348035550777', 'price_drop'))->toHaveCount(1);
});

it('respects the buyer\'s alert settings and keeps searches private', function () {
    ($this->save)(['make' => [$this->existing->make_id]]);
    $saved = SavedSearch::sole();

    $this->actingAs($this->buyer)->patch(route('saved-searches.update', $saved), ['channel' => 'app'])->assertSessionHas('success');
    app(PublishVehicle::class)->run(($this->draft)());
    expect($this->whatsapp->to('+2348035550777', 'saved_search_match'))->toHaveCount(0)
        ->and($this->buyer->notifications()->where('data->kind', 'alert')->count())->toBe(1);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->patch(route('saved-searches.update', $saved), ['channel' => 'phone'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('saved-searches.destroy', $saved))->assertNotFound();
    $this->actingAs($this->buyer)->delete(route('saved-searches.destroy', $saved))->assertSessionHas('success');
    expect(SavedSearch::count())->toBe(0);
});
