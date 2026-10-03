<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Illuminate\Console\Command;

/** Hourly (TDD: spotlights:expire): clear ended spotlights and update the search index. */
class ExpireSpotlights extends Command
{
    protected $signature = 'spotlights:expire';

    protected $description = 'Clear spotlights and featured slots that have ended';

    public function handle(): int
    {
        $cars = 0;
        Vehicle::withoutGlobalScopes()->with(['make', 'model', 'lot'])->where('spotlight_until', '<=', now())->each(function (Vehicle $vehicle) use (&$cars): void {
            $vehicle->spotlight_until = null;
            $vehicle->save(); // re-indexes
            $cars++;
        });

        $lots = Lot::where('featured_until', '<=', now())->update(['featured_until' => null]);

        $this->info("Ended {$cars} car spotlights and {$lots} featured sellers.");

        return self::SUCCESS;
    }
}
