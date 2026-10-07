<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\Support\Tracker;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Marketplace\SearchController;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public car search and car details for the app: the same `VehicleSearch` and filters as /search
 * (make, model, body_type, price_min/max, year, fuel, transmission, city, state, lat/lng/radius, sort, page),
 * shaped by `MarketplacePresenter`, so only marketplace cars and public fields come back.
 */
class CarController extends Controller
{
    public function index(Request $request, VehicleSearch $search): JsonResponse
    {
        $criteria = SearchCriteria::fromRequest($request);
        $from = $criteria->hasLocation() ? ['lat' => (float) $criteria->lat, 'lng' => (float) $criteria->lng] : null;
        $saved = $request->user('sanctum')?->favourites()->pluck('vehicles.id')->all() ?? [];
        $results = $search->search($criteria);

        return response()->json([
            'data' => $results->getCollection()->map(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $saved))->values(),
            'sponsored' => $search->sponsored($criteria)->map(fn (Vehicle $v) => MarketplacePresenter::card($v, $from, $saved))->values(),
            'filters' => $criteria->toArray(),
            'meta' => ['current_page' => $results->currentPage(), 'last_page' => $results->lastPage(), 'per_page' => $results->perPage(), 'total' => $results->total()],
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $vehicle = Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($ulid))
            ->with(['make', 'model', 'lot', 'cover', 'features', 'inspection', 'media' => fn ($q) => $q->where('status', 'ready')])
            ->firstOrFail();
        $user = $request->user('sanctum');

        if (! $user?->hasLotRole($vehicle->lot)) {
            Tracker::view($request, $vehicle);
        }
        $price = $vehicle->price !== null ? intdiv($vehicle->price, 100) : null;

        return response()->json(['data' => [
            ...MarketplacePresenter::vehicle($vehicle),
            'price_value' => $price,
            'lot' => MarketplacePresenter::lot($vehicle->lot),
            'saved' => $user ? $user->favourites()->whereKey($vehicle->id)->exists() : false,
        ]]);
    }

    /** Filter choices for the search screen. */
    public function filters(): JsonResponse
    {
        return response()->json(['data' => SearchController::filterOptions()]);
    }
}
