<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Carbon;

class ActivateSpotlight
{
    /**
     * Starts a paid spotlight. A second one for the same car or lot starts when the
     * current one ends, so days are never lost.
     */
    public function run(Spotlight $spotlight): Spotlight
    {
        if ($spotlight->status === 'paid') {
            return $spotlight;
        }

        $latest = Spotlight::withoutGlobalScopes()
            ->where('lot_id', $spotlight->lot_id)
            ->where('placement', $spotlight->placement)
            ->where('vehicle_id', $spotlight->vehicle_id)
            ->where('status', 'paid')
            ->max('ends_at');

        $start = $latest !== null && now()->lessThan($latest) ? Carbon::parse($latest) : now();
        $end = $start->copy()->addDays($spotlight->days);

        $spotlight->forceFill(['status' => 'paid', 'starts_at' => $start, 'ends_at' => $end])->save();

        if ($spotlight->placement === SpotlightPlacement::Car && $spotlight->vehicle_id !== null) {
            $vehicle = Vehicle::withoutGlobalScopes()->find($spotlight->vehicle_id);
            if ($vehicle !== null && ($vehicle->spotlight_until === null || $vehicle->spotlight_until->lessThan($end))) {
                // save(), not a query update, so the search index learns about it.
                $vehicle->spotlight_until = $end;
                $vehicle->save();
            }
        } else {
            $lot = Lot::findOrFail($spotlight->lot_id);
            if ($lot->featured_until === null || $lot->featured_until->lessThan($end)) {
                $lot->forceFill(['featured_until' => $end])->save();
            }
        }

        return $spotlight;
    }
}
