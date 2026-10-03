<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Advertising\Support\AdServer;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\SearchFacets;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Domain\Marketplace\Support\SavedSearches;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request, VehicleSearch $search): Response
    {
        $criteria = SearchCriteria::fromRequest($request);

        return self::render($request, $search, $criteria, [], [
            'title' => self::title($criteria),
            'description' => 'Browse cars for sale at sellers near you on CarYard: compare prices, check what you can afford and get directions to the seller.',
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
            // A seller's search banner that fits this search (make, body type or city), if any.
            'banner' => AdServer::search($criteria),
            'filters' => $criteria->toArray(),
            'activeFilters' => $criteria->activeFilterCount(),
            'options' => fn () => self::filterOptions(), // not reloaded by live search
            'landing' => null,
            'savedSearch' => $request->user() ? SavedSearches::matching($request->user(), $criteria) : null,
            ...$extra,
        ])->withViewData(['meta' => $meta]);
    }

    /** Filter choices from what is on sale, with counts (SearchFacets). */
    public static function filterOptions(): array
    {
        return SearchFacets::options();
    }

    private static function title(SearchCriteria $c): string
    {
        $make = count($c->makeIds) === 1 ? Make::whereKey($c->makeIds[0])->value('name') : null;
        $what = trim(($make ?? '').' '.(count($c->bodyTypes) === 1 ? BodyType::from($c->bodyTypes[0])->label().'s' : 'cars'));

        return ucfirst($what).' for sale'.($c->city ? " in {$c->city}" : '');
    }
}
