<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Advertising\Support\AdServer;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Domain\Marketplace\Support\SavedSearches;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, VehicleSearch $search): Response
    {
        $criteria = SearchCriteria::fromRequest($request);

        return self::render($request, $search, $criteria, [], [
            'title' => self::title($criteria),
            'description' => 'Browse cars for sale at car lots near you on LotLink: compare prices, check what you can afford and get directions to the lot.',
            'robots' => $request->hasAny(['page', 'lat', 'sort']) ? 'noindex, follow' : null,
        ]);
    }

    /**
     * The search page, also used by the SEO landing pages with their own heading and meta.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $meta
     */
    public static function render(Request $request, VehicleSearch $search, SearchCriteria $criteria, array $extra, array $meta): Response
    {
        $results = $search->search($criteria);
        $from = $criteria->hasLocation() ? ['lat' => (float) $criteria->lat, 'lng' => (float) $criteria->lng] : null;
        $savedIds = $request->user()?->favourites()->pluck('vehicles.id')->all() ?? [];

        return Inertia::render('Marketplace/Search', [
            'results' => $results->through(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $savedIds)),
            // Up to 3 spotlighted cars matching the search, labelled "Sponsored" (TDD M5).
            'sponsored' => $search->sponsored($criteria)->map(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $savedIds))->values(),
            'lotCount' => $results->getCollection()->pluck('lot.slug')->unique()->count(),
            // A lot's search banner that fits this search (make, body type or city), if any.
            'banner' => AdServer::search($criteria),
            'filters' => $criteria->toArray(),
            'activeFilters' => $criteria->activeFilterCount(),
            'options' => self::filterOptions(),
            'landing' => null,
            'savedSearch' => $request->user() ? SavedSearches::matching($request->user(), $criteria) : null,
            ...$extra,
        ])->withViewData(['meta' => $meta]);
    }

    /** Filter choices. Makes are limited to those with cars on the marketplace. */
    public static function filterOptions(): array
    {
        return [
            'makes' => Cache::remember('marketplace:makes', now()->addMinutes(10), fn () => Make::query()
                ->whereIn('id', Vehicle::query()->marketplace()->select('make_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray()),
            'body_types' => BodyType::options(),
            'conditions' => VehicleCondition::options(),
            'transmissions' => Transmission::options(),
            'fuels' => FuelType::options(),
            'radii' => SearchCriteria::RADII_KM,
        ];
    }

    private static function title(SearchCriteria $c): string
    {
        $make = count($c->makeIds) === 1 ? Make::whereKey($c->makeIds[0])->value('name') : null;
        $what = trim(($make ?? '').' '.(count($c->bodyTypes) === 1 ? BodyType::from($c->bodyTypes[0])->label().'s' : 'cars'));

        return ucfirst($what).' for sale'.($c->city ? " in {$c->city}" : '');
    }
}
