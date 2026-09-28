<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Analytics\Support\Tracker;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavouriteController extends Controller
{
    public function index(Request $request): Response
    {
        $cars = $request->user()->favourites()
            ->withoutGlobalScope('lot')
            ->with(['make', 'model', 'lot', 'cover'])
            ->latest('favourites.created_at')
            ->get()
            ->map(function (Vehicle $v) {
                $savedPrice = $v->pivot->getAttribute('saved_price');

                return [
                    ...MarketplacePresenter::card($v),
                    'sold' => $v->status === VehicleStatus::Sold,
                    'unavailable' => ! $v->isOnMarketplace() && $v->status !== VehicleStatus::Sold,
                    'price_drop' => $savedPrice && $v->price && $v->price < $savedPrice ? Money::format($savedPrice - $v->price, $v->currency) : null,
                    'old_price' => $savedPrice && $v->price && $v->price < $savedPrice ? Money::format($savedPrice, $v->currency) : null,
                ];
            });

        return Inertia::render('Marketplace/Saved', ['cars' => $cars])
            ->withViewData(['meta' => ['title' => 'Saved cars', 'robots' => 'noindex']]);
    }

    public function store(Request $request, string $vehicle): RedirectResponse
    {
        $this->save($request, $vehicle);

        return back();
    }

    /** Guests tapping the heart come back here after signing in, so the save isn't lost. */
    public function remember(Request $request, string $vehicle): RedirectResponse
    {
        $car = $this->save($request, $vehicle);

        return redirect($car->publicPath())->with('success', 'Saved. Find it any time under Saved.');
    }

    public function destroy(Request $request, string $vehicle): RedirectResponse
    {
        $request->user()->favourites()->detach($this->find($vehicle, onMarketplace: false)->id);

        return back();
    }

    private function save(Request $request, string $ulid): Vehicle
    {
        $car = $this->find($ulid, onMarketplace: true);
        $changes = $request->user()->favourites()->syncWithoutDetaching([$car->id => ['saved_price' => $car->price]]);
        if ($changes['attached'] !== []) {
            Tracker::record('save', $car->lot_id, $car->id);
        }

        return $car;
    }

    private function find(string $ulid, bool $onMarketplace): Vehicle
    {
        $query = $onMarketplace ? Vehicle::query()->marketplace() : Vehicle::query()->withoutGlobalScope('lot');

        return $query->where('vehicles.ulid', strtolower($ulid))->firstOrFail();
    }
}
