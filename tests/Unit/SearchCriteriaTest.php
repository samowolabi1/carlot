<?php

use App\Domain\Marketplace\Search\SearchCriteria;
use Illuminate\Http\Request;

it('reads filters from the query string, with naira turned into kobo', function () {
    $c = SearchCriteria::fromRequest(Request::create('/cars', 'GET', [
        'q' => ' camry ', 'make' => ['3', 'x', '0'], 'body' => ['suv', 'spaceship'], 'price_max' => '12000000',
        'transmission' => 'automatic', 'lat' => '6.6', 'lng' => '3.35', 'radius' => '10', 'page' => '2',
    ]));

    expect($c)
        ->query->toBe('camry')
        ->makeIds->toBe([3])
        ->bodyTypes->toBe(['suv'])
        ->priceMax->toBe(1_200_000_000)
        ->transmission->toBe('automatic')
        ->radiusKm->toBe(10)
        ->sort->toBe('nearest')
        ->page->toBe(2)
        ->and($c->toArray()['price_max'])->toBe(12_000_000)
        ->and($c->activeFilterCount())->toBe(5);
});

it('ignores a radius or nearest sort without a location', function () {
    $c = SearchCriteria::fromRequest(Request::create('/cars', 'GET', ['radius' => '10', 'sort' => 'nearest']));

    expect($c->radiusKm)->toBeNull()->and($c->sort)->toBe('newest');
});

it('only accepts the offered radii', function () {
    expect(SearchCriteria::fromRequest(Request::create('/cars', 'GET', ['lat' => '6', 'lng' => '3', 'radius' => '7']))->radiusKm)->toBeNull();
});
