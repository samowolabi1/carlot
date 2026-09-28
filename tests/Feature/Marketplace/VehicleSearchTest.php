<?php

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

/*
 * The same behaviour from both engines: MySQL for local development, Meilisearch in
 * production (TDD M4).
 */

beforeEach(function () {
    // Ikeja, Lekki and Abuja lots; the buyer is in Ikeja.
    $this->ikeja = Lot::factory()->active()->create(['name' => 'Prime Motors', 'city' => 'Ikeja', 'latitude' => 6.6018, 'longitude' => 3.3515]);
    $this->lekki = Lot::factory()->active()->create(['name' => 'Ace Autos', 'city' => 'Lekki', 'latitude' => 6.4474, 'longitude' => 3.4723]);
    $this->abuja = Lot::factory()->active()->create(['name' => 'Capital Cars', 'city' => 'Abuja', 'latitude' => 9.0765, 'longitude' => 7.3986]);
    $this->pending = Lot::factory()->create(['name' => 'Not Yet Motors']);
});

function seedCars(): array
{
    $t = test();

    return [
        'camry' => $t->car($t->ikeja, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000, 'mileage_km' => 62_400, 'body_type' => 'sedan', 'listed_at' => now()->subDays(2)]),
        'rav4' => $t->car($t->lekki, 'Toyota', 'RAV4', ['year' => 2017, 'price' => 1_420_000_000, 'mileage_km' => 70_500, 'body_type' => 'suv', 'listed_at' => now()->subDays(5)]),
        'accord' => $t->car($t->abuja, 'Honda', 'Accord', ['year' => 2015, 'price' => 690_000_000, 'mileage_km' => 120_000, 'body_type' => 'sedan', 'transmission' => 'manual', 'listed_at' => now()->subDay()]),
        'hidden' => tap($t->car($t->ikeja, 'Toyota', 'Corolla', ['year' => 2016, 'price' => 790_000_000, 'body_type' => 'sedan']), fn ($v) => $v->forceFill(['status' => 'hidden'])->save()),
        'pendingLot' => $t->car($t->pending, 'Toyota', 'Highlander', ['year' => 2019, 'price' => 2_000_000_000, 'body_type' => 'suv']),
    ];
}

function run(array $criteria): array
{
    test()->reindex();

    return app(VehicleSearch::class)->search(new SearchCriteria(...$criteria))->getCollection()->map(fn (Vehicle $v) => $v->model->name)->all();
}

dataset('engines', ['database', 'meilisearch']);

afterEach(fn () => $this->tearDownMeilisearch());

it('shows only available and reserved cars at approved lots, newest first', function (string $engine) {
    $this->useSearchEngine($engine);
    seedCars();

    expect(run([]))->toBe(['Accord', 'Camry', 'RAV4']);
})->with('engines');

it('filters by make, body type, transmission and ranges', function (string $engine) {
    $this->useSearchEngine($engine);
    $cars = seedCars();

    expect(run(['makeIds' => [$cars['camry']->make_id]]))->toEqualCanonicalizing(['Camry', 'RAV4'])
        ->and(run(['bodyTypes' => ['suv']]))->toBe(['RAV4'])
        ->and(run(['transmission' => 'manual']))->toBe(['Accord'])
        ->and(run(['priceMin' => 1_000_000_000, 'priceMax' => 1_300_000_000]))->toBe(['Camry'])
        ->and(run(['yearMin' => 2017, 'sort' => 'year_desc']))->toBe(['Camry', 'RAV4'])
        ->and(run(['mileageMax' => 65_000]))->toBe(['Camry'])
        ->and(run(['lotId' => $this->lekki->id]))->toBe(['RAV4']);
})->with('engines');

it('matches words in the make, model and lot name', function (string $engine) {
    $this->useSearchEngine($engine);
    seedCars();

    expect(run(['query' => 'toyota']))->toEqualCanonicalizing(['Camry', 'RAV4'])
        ->and(run(['query' => 'camry']))->toBe(['Camry'])
        ->and(run(['query' => 'Capital']))->toBe(['Accord']);
})->with('engines');

it('sorts by price', function (string $engine) {
    $this->useSearchEngine($engine);
    seedCars();

    expect(run(['sort' => 'price_asc']))->toBe(['Accord', 'Camry', 'RAV4'])
        ->and(run(['sort' => 'price_desc']))->toBe(['RAV4', 'Camry', 'Accord']);
})->with('engines');

it('finds cars near the buyer', function (string $engine) {
    $this->useSearchEngine($engine);
    seedCars();
    $ikeja = ['lat' => 6.6018, 'lng' => 3.3515];

    // Lekki is about 22 km from Ikeja; Abuja about 520 km.
    expect(run([...$ikeja, 'sort' => 'nearest']))->toBe(['Camry', 'RAV4', 'Accord'])
        ->and(run([...$ikeja, 'radiusKm' => 10]))->toBe(['Camry'])
        ->and(run([...$ikeja, 'radiusKm' => 25, 'sort' => 'nearest']))->toBe(['Camry', 'RAV4']);
})->with('engines');

it('drops cars from results when their lot is suspended', function (string $engine) {
    $this->useSearchEngine($engine);
    seedCars();

    $this->lekki->update(['status' => 'suspended']);

    expect(run([]))->toBe(['Accord', 'Camry']);
})->with('engines');
