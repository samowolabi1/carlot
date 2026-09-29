<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Actions\SaveCar;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Saved cars (the heart), with price drops since saving. */
class SavedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cars = $request->user()->favourites()->withoutGlobalScope('lot')->with(['make', 'model', 'lot', 'cover'])
            ->latest('favourites.created_at')->get()
            ->map(function (Vehicle $v) {
                $savedPrice = $v->pivot->getAttribute('saved_price');
                $dropped = $savedPrice && $v->price && $v->price < $savedPrice;

                return [
                    ...MarketplacePresenter::card($v, null, [$v->id]),
                    'sold' => $v->status === VehicleStatus::Sold,
                    'unavailable' => ! $v->isOnMarketplace() && $v->status !== VehicleStatus::Sold,
                    'price_drop' => $dropped ? Money::format($savedPrice - $v->price, $v->currency) : null,
                ];
            });

        return response()->json(['data' => $cars]);
    }

    public function store(Request $request, string $vehicle, SaveCar $save): JsonResponse
    {
        $save->run($request->user(), $vehicle);

        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request, string $vehicle, SaveCar $save): JsonResponse
    {
        $save->remove($request->user(), $vehicle);

        return response()->json(['saved' => false]);
    }
}
