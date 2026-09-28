<?php

namespace App\Domain\Sharing\Jobs;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Sharing\Support\ShareCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * RenderShareCard (TDD M9): runs when a car is published, its price, details or cover
 * change, or the lot's name, phone or logo change. Does nothing if the card is current.
 */
class RenderShareCard implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $vehicleId)
    {
        $this->onQueue('media');
        $this->afterCommit();
    }

    /** Queue a re-render if cards are on (tests turn them off unless they test them). */
    public static function refresh(int $vehicleId): void
    {
        if (config('lotlink.share_cards')) {
            self::dispatch($vehicleId);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->vehicleId;
    }

    public function handle(ShareCard $cards): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->with(['make', 'model', 'lot', 'cover'])->find($this->vehicleId);

        if ($vehicle === null || ! $vehicle->isOnMarketplace() || $vehicle->price === null) {
            return;
        }

        if ($vehicle->share_card_hash === $cards->hash($vehicle)) {
            return;
        }

        $hash = $cards->render($vehicle);

        // A query update: no model events, so search isn't re-synced for a picture.
        Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->update(['share_card_hash' => $hash]);
    }
}
