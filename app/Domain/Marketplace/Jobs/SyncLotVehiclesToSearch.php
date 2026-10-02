<?php

namespace App\Domain\Marketplace\Jobs;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A lot's name, place, pin, approval, plan or buyer settings (loans, trade-ins, offers, verified) changed: refresh its cars in the search index,
 * adding them when the lot goes live and removing them when it is suspended.
 */
class SyncLotVehiclesToSearch implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $lotId) {}

    public function handle(): void
    {
        Vehicle::withoutGlobalScopes()
            ->where('lot_id', $this->lotId)
            ->whereIn('status', ['available', 'reserved'])
            ->with(['make', 'model', 'lot.plan', 'features'])
            ->chunkById(200, function ($vehicles): void {
                $index = new Vehicle;
                $index->queueMakeSearchable($vehicles->filter(fn (Vehicle $v) => $v->shouldBeSearchable())->values());
                $index->queueRemoveFromSearch($vehicles->reject(fn (Vehicle $v) => $v->shouldBeSearchable())->values());
            });
    }
}
