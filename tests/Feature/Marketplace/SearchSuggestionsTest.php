<?php

use App\Domain\Lots\Models\Lot;
use App\Http\Middleware\HandleInertiaRequests;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

/* The search box suggests, as people type, only what's on sale: makes, models, places, lots and cars. */

beforeEach(function () {
    $this->ikeja = Lot::factory()->active()->create(['name' => 'Prime Motors', 'city' => 'Ikeja', 'state' => 'Lagos']);
    $this->lekki = Lot::factory()->active()->create(['name' => 'Toyin Autos', 'city' => 'Lekki', 'state' => 'Lagos']);
    $this->pending = Lot::factory()->create(['name' => 'Toyland Cars', 'city' => 'Yaba']);
});

dataset('engines', ['database', 'meilisearch']);

afterEach(fn () => $this->tearDownMeilisearch());

it('suggests makes, models, places, lots and cars with links', function (string $engine) {
    $this->useSearchEngine($engine);
    $this->car($this->ikeja, 'Toyota', 'Camry', ['year' => 2018]);
    $this->car($this->lekki, 'Toyota', 'Corolla', ['year' => 2016]);
    $this->car($this->lekki, 'Toyota', 'Corolla', ['year' => 2017]);
    $this->car($this->pending, 'Toyota', 'Land Cruiser'); // lot not live
    tap($this->car($this->ikeja, 'Toyota', 'Avalon'), fn ($v) => $v->forceFill(['status' => 'hidden'])->save());
    $this->reindex();

    $data = $this->getJson(route('search.suggest', ['q' => 'toy']))->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, public')
        ->json();

    expect($data['makes'])->toBe([['label' => 'Toyota', 'url' => url('/cars/toyota'), 'count' => 3]])
        // Most cars first; models with nothing on sale aren't suggested.
        ->and(array_column($data['models'], 'label'))->toBe(['Toyota Corolla', 'Toyota Camry'])
        ->and($data['models'][0]['url'])->toBe(url('/cars/toyota/corolla'))
        // Lots that are live, not ones waiting for approval.
        ->and(array_column($data['lots'], 'label'))->toBe(['Toyin Autos'])
        ->and(array_column($data['cars'], 'label'))->toEqualCanonicalizing(['2018 Toyota Camry', '2016 Toyota Corolla', '2017 Toyota Corolla']);

    expect($this->getJson(route('search.suggest', ['q' => 'ikej']))->json('places'))->toBe([['label' => 'Cars in Ikeja', 'url' => url('/cars/ikeja')]]);
})->with('engines');

it('waits for two letters, can leave cars out, and caps the query', function () {
    $this->useSearchEngine('database');
    $this->car($this->ikeja, 'Honda', 'Accord');

    expect($this->getJson(route('search.suggest', ['q' => 'h']))->json())->toMatchArray(['makes' => [], 'models' => [], 'cars' => []])
        ->and($this->getJson(route('search.suggest', ['q' => 'hon', 'cars' => 0]))->json('cars'))->toBe([])
        ->and($this->getJson(route('search.suggest', ['q' => 'hon', 'cars' => 0]))->json('makes.0.label'))->toBe('Honda');
    $this->getJson(route('search.suggest', ['q' => str_repeat('a', 81)]))->assertUnprocessable();
});

it('matches whole words first and only matches inside words from three letters', function () {
    $this->useSearchEngine('database');
    $this->car($this->ikeja, 'Toyota', 'Camry');

    // "to" starts "Toyota" and "Toyin Autos", but only appears inside "Prime Motors".
    expect(array_column($this->getJson(route('search.suggest', ['q' => 'to']))->json('lots'), 'label'))->toBe(['Toyin Autos'])
        ->and(array_column($this->getJson(route('search.suggest', ['q' => 'mot']))->json('lots'), 'label'))->toBe(['Prime Motors']);
});

it('reloads only the results when the search changes as people type', function () {
    $this->useSearchEngine('database');
    $this->car($this->ikeja, 'Honda', 'Accord');
    $this->car($this->ikeja, 'Toyota', 'Camry');

    $props = $this->get(route('cars.index', ['q' => 'honda']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Marketplace/Search',
        'X-Inertia-Partial-Data' => 'results,filters,activeFilters',
    ])->assertOk()->json('props');

    expect($props)->toHaveKeys(['results', 'filters', 'activeFilters'])->not->toHaveKey('options')
        ->and($props['results']['data'])->toHaveCount(1)
        ->and($props['results']['data'][0]['title'])->toContain('Honda Accord')
        ->and($props['filters']['q'])->toBe('honda');
});
