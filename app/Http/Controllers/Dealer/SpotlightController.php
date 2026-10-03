<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Billing\Actions\BuySpotlight;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Support\SpotlightPricing;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Buying spotlights (TDD M5): a car for 7/14/30 days, or the seller in "Featured lots". */
class SpotlightController extends Controller
{
    /** Prices and the free allowance, for the spotlight sheet. */
    public function options(Lot $lot): JsonResponse
    {
        Gate::authorize('buySpotlight', $lot);
        $lot->loadMissing('plan');

        return response()->json([
            'car' => SpotlightPricing::options(SpotlightPlacement::Car),
            'free_left' => SpotlightPricing::freeLeft($lot),
        ]);
    }

    public function car(Request $request, Lot $lot, Vehicle $vehicle, BuySpotlight $buy): Response
    {
        Gate::authorize('buySpotlight', $lot);
        $data = $request->validate(['days' => Fields::count(1, 365), 'free' => ['boolean']]);

        $result = $buy->run($lot, $request->user(), SpotlightPlacement::Car, (int) $data['days'], $vehicle, (bool) ($data['free'] ?? false));

        return $result['checkout'] !== null
            ? Inertia::location($result['checkout'])
            : back()->with('success', 'Free spotlight on. The car shows first in search for 7 days.');
    }

    public function featured(Request $request, Lot $lot, BuySpotlight $buy): Response
    {
        Gate::authorize('buySpotlight', $lot);
        $data = $request->validate(['days' => Fields::count(1, 365)]);

        return Inertia::location($buy->run($lot, $request->user(), SpotlightPlacement::FeaturedLot, (int) $data['days'])['checkout'] ?? route('dealer.billing', $lot));
    }
}
