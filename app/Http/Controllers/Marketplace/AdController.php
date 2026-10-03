<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Analytics\Support\Tracker;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Advert clicks and views: counted once per visit, never for bots or the seller's own staff. */
class AdController extends Controller
{
    /** /ad/{ulid}: counts the click and opens the car or the seller. */
    public function click(Request $request, AdCampaign $campaign): RedirectResponse
    {
        if ($this->counts($request, $campaign, 'clicked')) {
            AdCampaign::withoutGlobalScopes()->whereKey($campaign->id)->increment('clicks');
        }

        $lot = Lot::withoutGlobalScopes()->findOrFail($campaign->lot_id);
        $vehicle = $campaign->vehicle;

        return redirect($vehicle !== null && $vehicle->isOnMarketplace() ? $vehicle->publicPath() : route('lots.show', $lot));
    }

    /** The banner was on screen (sent by the page once per banner). */
    public function seen(Request $request, AdCampaign $campaign): Response
    {
        if ($this->counts($request, $campaign, 'seen')) {
            AdCampaign::withoutGlobalScopes()->whereKey($campaign->id)->increment('impressions');
        }

        return response()->noContent();
    }

    private function counts(Request $request, AdCampaign $campaign, string $what): bool
    {
        if (Tracker::isBot($request->userAgent())) {
            return false;
        }

        $lot = Lot::withoutGlobalScopes()->find($campaign->lot_id);
        if ($lot !== null && $request->user()?->hasLotRole($lot)) {
            return false;
        }

        $key = "ads.{$what}.{$campaign->ulid}";
        if ($request->session()->has($key)) {
            return false;
        }
        $request->session()->put($key, true);

        return true;
    }
}
