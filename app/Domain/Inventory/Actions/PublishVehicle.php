<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Events\VehiclePublished;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Jobs\SendPriceAlerts;
use App\Domain\Sharing\Jobs\RenderShareCard;
use App\Domain\Social\Jobs\PublishToSocial;
use App\Domain\Trust\Jobs\DetectFraudSignals;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishVehicle
{
    public function __construct(private readonly VehicleStateMachine $stateMachine) {}

    /** Takes a draft or hidden car live, within the seller's plan listing limit. */
    public function run(Vehicle $vehicle): Vehicle
    {
        if ($vehicle->isHeld()) {
            throw ValidationException::withMessages(['publish' => 'CarYard is reviewing this car. It goes back on sale once the review is done.']);
        }

        $this->ensureComplete($vehicle);

        return DB::transaction(function () use ($vehicle): Vehicle {
            $lot = Lot::whereKey($vehicle->lot_id)->lockForUpdate()->with('plan')->firstOrFail();
            $this->ensureWithinLimit($lot);

            $firstPublish = $vehicle->listed_at === null;
            $this->stateMachine->transition($vehicle, VehicleStatus::Available);

            if ($firstPublish) {
                DB::afterCommit(fn () => VehiclePublished::dispatch($vehicle));
                // Saved-search alerts (TDD M4).
                DB::afterCommit(fn () => SendPriceAlerts::dispatch($vehicle->id));
                // Facebook/Instagram auto-post (TDD M9), if the seller connected a Page.
                DB::afterCommit(fn () => PublishToSocial::dispatch($vehicle->id));
            }

            // Duplicate VIN or photo, very low price, new-lot bursts (TDD M14): for an admin to check.
            DB::afterCommit(fn () => DetectFraudSignals::dispatch($vehicle->id));

            RenderShareCard::refresh($vehicle->id);

            return $vehicle;
        });
    }

    /** @return list<string> what still needs doing before the car can go live */
    public function missing(Vehicle $vehicle): array
    {
        $missing = [];

        if ($vehicle->make_id === null || $vehicle->vehicle_model_id === null || $vehicle->year === null) {
            $missing[] = 'make, model and year';
        }

        if ($vehicle->mileage_km === null || $vehicle->condition === null || $vehicle->transmission === null || $vehicle->fuel === null) {
            $missing[] = 'mileage, condition, transmission and fuel';
        }

        if (! $vehicle->media()->where('status', MediaStatus::Ready)->exists()) {
            $missing[] = 'at least one photo';
        }

        if (! $vehicle->price) {
            $missing[] = 'a price';
        }

        return $missing;
    }

    private function ensureComplete(Vehicle $vehicle): void
    {
        $missing = $this->missing($vehicle);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'publish' => 'Add '.implode(', ', $missing).' before publishing.',
            ]);
        }
    }

    private function ensureWithinLimit(Lot $lot): void
    {
        $limit = $lot->plan?->listing_limit;

        if ($limit === null) {
            return;
        }

        $live = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->live()->whereNull('deleted_at')->count();

        if ($live >= $limit) {
            throw ValidationException::withMessages([
                'publish' => "Your {$lot->plan->name} plan includes {$limit} live listings. Upgrade, or hide or sell a car to list this one.",
            ]);
        }
    }
}
