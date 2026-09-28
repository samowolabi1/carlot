<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The lot's mini-site at /l/{slug}, which lots share as their own website (M6). */
class LotSiteController extends Controller
{
    public function __invoke(Request $request, Lot $lot, VehicleSearch $search): Response
    {
        $preview = $lot->status !== LotStatus::Active;
        abort_if($preview && ! $request->user()?->hasLotRole($lot), 404);

        $criteria = SearchCriteria::fromRequest($request, lotId: $lot->id);
        $savedIds = $request->user()?->favourites()->pluck('vehicles.id')->all() ?? [];
        $stock = $search->search($criteria);

        return Inertia::render('Marketplace/Lot', [
            'lot' => MarketplacePresenter::lot($lot),
            'stock' => $stock->through(fn (Vehicle $v) => MarketplacePresenter::card($v, null, $savedIds)),
            'total' => Vehicle::query()->marketplace()->where('vehicles.lot_id', $lot->id)->count(),
            'filters' => $criteria->toArray(),
            'preview' => $preview,
        ])->withViewData(['meta' => [
            'title' => $lot->name.($lot->city ? " — cars for sale in {$lot->city}" : ''),
            'description' => $lot->tagline ?? "See {$lot->name}'s cars, opening hours and directions on LotLink.",
            'image' => $lot->cover_url ?? $lot->logo_url,
            'url' => route('lots.show', $lot),
            'robots' => $preview ? 'noindex' : null,
        ]]);
    }
}
