<?php

use App\Domain\Inventory\Models\Feature;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\SearchFacets;
use App\Domain\Marketplace\Support\SavedSearches;
use Illuminate\Http\Request;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

beforeEach(function () {
    $this->ikeja = Lot::factory()->active()->create(['city' => 'Ikeja', 'state' => 'Lagos', 'accepts_finance' => true]);
    $this->abuja = Lot::factory()->active()->create(['city' => 'Garki', 'state' => 'FCT', 'accepts_finance' => false]);
    $this->camry = $this->car($this->ikeja, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000, 'body_type' => 'sedan', 'colour' => 'Silver', 'drivetrain' => 'fwd']);
    $this->rav4 = $this->car($this->ikeja, 'Toyota', 'RAV4', ['year' => 2016, 'price' => 1_420_000_000, 'body_type' => 'suv', 'colour' => 'silver ', 'drivetrain' => 'awd']);
    $this->accord = $this->car($this->abuja, 'Honda', 'Accord', ['year' => 2014, 'price' => 690_000_000, 'body_type' => 'sedan', 'colour' => 'Black']);
    $this->car(Lot::factory()->create(), 'Lexus', 'RX 350', ['body_type' => 'pickup']); // lot not live: never offered
    $this->camera = Feature::firstOrCreate(['slug' => 'reverse-camera'], ['name' => 'Reverse camera', 'group' => 'safety']);
    $this->camry->features()->sync([$this->camera->id]);
});

it('offers only choices that have cars on sale, each with how many', function () {
    $o = SearchFacets::options();

    expect(collect($o['makes'])->pluck('count', 'name')->all())->toBe(['Honda' => 1, 'Toyota' => 2])
        ->and(collect($o['models'])->pluck('name')->all())->toBe(['Accord', 'Camry', 'RAV4'])
        ->and(collect($o['body_types'])->pluck('count', 'value')->all())->toBe(['sedan' => 2, 'suv' => 1]) // no pickup: that lot isn't live
        ->and(collect($o['colours'])->pluck('count', 'value')->all())->toBe(['silver' => 2, 'black' => 1]) // "Silver" and "silver " together
        ->and(collect($o['drivetrains'])->pluck('value')->all())->toBe(['fwd', 'awd'])
        ->and(collect($o['states'])->pluck('count', 'label')->all())->toBe(['FCT (Abuja)' => 1, 'Lagos' => 2])
        ->and(collect($o['cities'])->pluck('state', 'value')->all())->toBe(['Garki' => 'FCT', 'Ikeja' => 'Lagos'])
        ->and(collect($o['features'])->pluck('count', 'name')->all())->toBe(['Reverse camera' => 1])
        ->and($o['years'])->toBe(['min' => 2014, 'max' => 2018])
        ->and($o['prices'])->toBe(['min' => 6_900_000, 'max' => 14_200_000]);
});

it('shows "Car loans available" only while an approved lender takes applications', function () {
    $extras = fn () => collect(SearchFacets::options())->get('extras');

    expect(collect($extras())->pluck('value'))->not->toContain('loans');

    SearchFacets::forget();
    $this->lender();
    expect(collect($extras())->firstWhere('value', 'loans'))->toMatchArray(['label' => 'Car loans available', 'count' => 2]);
});

it('reads the new filters from the address, ignoring anything unknown, and saves them with a search', function () {
    $c = SearchCriteria::fromRequest(Request::create('/cars', 'GET', [
        'model' => '7', 'drive' => ['awd', 'hover'], 'colour' => [' Silver ', str_repeat('x', 50)], 'feature' => ['3', 'x', '3'], 'has' => ['loans', 'free_fuel', 'inspected'],
    ]));

    expect($c->modelIds)->toBe([7])
        ->and($c->drivetrains)->toBe(['awd'])
        ->and($c->colours)->toBe(['silver'])
        ->and($c->featureIds)->toBe([3])
        ->and($c->extras)->toBe(['loans', 'inspected'])
        ->and($c->activeFilterCount())->toBe(6)
        ->and(SavedSearches::filters($c))->toMatchArray(['has' => ['loans', 'inspected'], 'feature' => [3], 'drive' => ['awd']])
        ->and(SavedSearches::describe(SavedSearches::filters($c)))->toContain('car loans available, inspected');
});

it('gives the search page and the app the same choices', function () {
    $this->get('/cars?has[]=inspected&colour[]=silver')->assertOk()->assertInertia(fn ($page) => $page
        ->where('filters.has', ['inspected'])
        ->where('filters.colour', ['silver'])
        ->has('options.extras')
        ->where('options.makes.1.count', 2));

    $this->getJson('/api/v1/cars/filters')->assertOk()->assertJsonPath('data.makes.1.name', 'Toyota')->assertJsonPath('data.colours.0.value', 'silver');
    $this->getJson('/api/v1/cars?colour[]=black')->assertOk()->assertJsonCount(1, 'data');
});
