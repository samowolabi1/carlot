<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Domains\CustomDomains;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, VehicleSearch $search): Response
    {
        // A lot's own domain (TDD M6, Enterprise) opens straight on its mini-site.
        if ($lot = CustomDomains::lotFor($request->getHost())) {
            return app(LotSiteController::class)($request, $lot, $search);
        }

        // Newest first; nearest first once the buyer has shared their location.
        $criteria = new SearchCriteria(
            lat: is_numeric($request->query('lat')) ? (float) $request->query('lat') : null,
            lng: is_numeric($request->query('lng')) ? (float) $request->query('lng') : null,
            sort: is_numeric($request->query('lat')) && is_numeric($request->query('lng')) ? 'nearest' : 'newest',
            perPage: 8,
        );
        $from = $criteria->hasLocation() ? ['lat' => (float) $criteria->lat, 'lng' => (float) $criteria->lng] : null;
        $savedIds = $request->user()?->favourites()->pluck('vehicles.id')->all() ?? [];

        return Inertia::render('Home', [
            'arrivals' => $search->search($criteria)->getCollection()->map(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $savedIds)),
            // Spotlighted cars rotate on each visit; the row is hidden when there are none.
            'spotlight' => Vehicle::query()->marketplace()->where('vehicles.spotlight_until', '>', now())
                ->with(['make', 'model', 'lot', 'cover'])->inRandomOrder()->limit(8)->get()
                ->map(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $savedIds)),
            'featuredLots' => Lot::active()->where('featured_until', '>', now())->withCount(['vehicles as live_count' => fn ($q) => $q->withoutGlobalScopes()->whereIn('status', ['available', 'reserved'])])
                ->inRandomOrder()->limit(6)->get()
                ->map(fn (Lot $lot) => [
                    'slug' => $lot->slug,
                    'url' => route('lots.show', $lot),
                    'name' => $lot->name,
                    'initials' => $lot->initials(),
                    'logo_url' => $lot->logo_url,
                    'city' => $lot->city,
                    'verified' => $lot->verified_at !== null,
                    'cars' => (int) $lot->getAttribute('live_count'),
                ]),
            'carCount' => Vehicle::query()->marketplace()->count(),
            'lotCount' => Lot::active()->count(),
            'nearMe' => $criteria->hasLocation(),
            'options' => SearchController::filterOptions(),
        ])->withViewData(['meta' => [
            'title' => 'LotLink: cars for sale at lots near you',
            'description' => 'Find your next car at car lots near you. Browse stock, compare prices and get directions to the lot.',
        ]]);
    }
}
