<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A live lot's public page and bookable times, for the app. */
class LotController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $lot = Lot::query()->active()->where('slug', $slug)->firstOrFail();
        $saved = $request->user('sanctum')?->favourites()->pluck('vehicles.id')->all() ?? [];
        $cars = Vehicle::query()->marketplace()->where('vehicles.lot_id', $lot->id)->with(['make', 'model', 'lot', 'cover'])
            ->latest('listed_at')->paginate(24);

        return response()->json([
            'data' => [
                ...MarketplacePresenter::lot($lot),
                'following' => $request->user('sanctum')?->followedLots()->whereKey($lot->id)->exists() ?? false,
            ],
            'cars' => $cars->getCollection()->map(fn (Vehicle $v) => MarketplacePresenter::card($v, null, $saved))->values(),
            'meta' => ['current_page' => $cars->currentPage(), 'last_page' => $cars->lastPage(), 'total' => $cars->total()],
        ]);
    }

    /** Bookable times for the next 14 days (lot time zone in, UTC `starts_at` out). */
    public function slots(string $slug, SlotGenerator $slots): JsonResponse
    {
        $lot = Lot::query()->active()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $slots->days($lot), 'timezone' => $lot->timezone]);
    }
}
