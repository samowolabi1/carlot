<?php

namespace App\Domain\Marketplace\Jobs;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Marketplace\Notifications\PriceDropped;
use App\Domain\Marketplace\Notifications\SavedSearchMatch;
use App\Domain\Marketplace\Search\DatabaseVehicleSearch;
use App\Domain\Marketplace\Support\SavedSearches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * MatchSavedSearches (TDD M4): when a car is published or its price drops, tell buyers whose
 * saved searches it matches (at most one alert per search every 6 hours) and, on a drop, the
 * buyers who saved the car. The lot's own team is never alerted about its own cars.
 */
class SendPriceAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $vehicleId, public readonly ?int $oldPrice = null)
    {
        $this->onQueue('default');
    }

    public function handle(DatabaseVehicleSearch $search): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->with(['make', 'model', 'lot'])->find($this->vehicleId);

        if ($vehicle === null || ! $vehicle->isOnMarketplace()) {
            return;
        }

        $team = $vehicle->lot->members()->pluck('users.id')->all();
        $priceDrop = $this->oldPrice !== null && $vehicle->price !== null && $vehicle->price < $this->oldPrice;
        $alerted = [];

        if ($priceDrop) {
            $savers = User::whereHas('favourites', fn ($q) => $q->where('vehicles.id', $vehicle->id))->whereNotIn('id', $team)->get();
            Notification::send($savers, new PriceDropped($vehicle, (int) $this->oldPrice, (int) $vehicle->price));
            $alerted = $savers->pluck('id')->all();
        }

        SavedSearch::query()->whereNotIn('user_id', [...$team, ...$alerted])
            ->where(fn ($q) => $q->whereNull('last_notified_at')->orWhere('last_notified_at', '<', now()->subHours(SavedSearch::ALERT_EVERY_HOURS)))
            ->with('user')
            ->chunkById(200, function ($searches) use ($search, $vehicle, $priceDrop): void {
                $told = [];
                foreach ($searches as $saved) {
                    // One alert per buyer for this car, even if several of their searches match.
                    if ($saved->user === null || isset($told[$saved->user_id]) || ! $search->matches(SavedSearches::criteria($saved->filters), $vehicle)) {
                        continue;
                    }

                    $saved->forceFill(['last_notified_at' => now()])->save();
                    $saved->user->notify(new SavedSearchMatch($saved, $vehicle, $priceDrop));
                    $told[$saved->user_id] = true;
                }
            });
    }
}
