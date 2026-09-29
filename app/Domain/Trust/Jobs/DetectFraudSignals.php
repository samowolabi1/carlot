<?php

namespace App\Domain\Trust\Jobs;

use App\Domain\Analytics\Support\PricingGuide;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Support\ImageHash;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\FraudSignalType;
use App\Domain\Trust\Models\FraudSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs when a car is published or its price changes (TDD M14). Adds signals to the admin
 * review queue; it never hides or blocks anything by itself.
 */
class DetectFraudSignals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A price below this share of the pricing-guide median is flagged. */
    public const LOW_PRICE_RATIO = 0.4;

    /** Lots younger than this that publish more than BURST_LIMIT cars in 24 hours are flagged. */
    public const NEW_LOT_DAYS = 30;

    public const BURST_LIMIT = 30;

    public function __construct(public readonly int $vehicleId) {}

    public function handle(): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->with(['cover', 'lot'])->find($this->vehicleId);

        if ($vehicle === null || $vehicle->listed_at === null || $vehicle->status === VehicleStatus::Draft) {
            return;
        }

        $this->duplicateVin($vehicle);
        $this->duplicatePhoto($vehicle);
        $this->lowPrice($vehicle);
        $this->burst($vehicle);
    }

    private function duplicateVin(Vehicle $vehicle): void
    {
        if ($vehicle->vin === null || strlen($vehicle->vin) < 11) {
            return;
        }

        $other = Vehicle::withoutGlobalScopes()->with('lot')
            ->where('vin', $vehicle->vin)->where('lot_id', '!=', $vehicle->lot_id)
            ->whereNotNull('listed_at')->latest('listed_at')->first();

        if ($other !== null) {
            $this->flag($vehicle, FraudSignalType::DuplicateVin, $other, ['other_lot' => $other->lot->name, 'vin_tail' => $vehicle->vinTail()]);
        }
    }

    /** The cover photo against other lots' covers of the same make (dHash, a few bits apart). */
    private function duplicatePhoto(Vehicle $vehicle): void
    {
        $hash = $vehicle->cover?->phash;

        if ($hash === null) {
            return;
        }

        $match = null;
        VehicleMedia::query()
            ->join('vehicles', 'vehicles.id', '=', 'vehicle_media.vehicle_id')
            ->where('vehicle_media.is_cover', true)->whereNotNull('vehicle_media.phash')
            ->where('vehicles.lot_id', '!=', $vehicle->lot_id)
            ->when($vehicle->make_id, fn ($q) => $q->where('vehicles.make_id', $vehicle->make_id))
            ->whereNotNull('vehicles.listed_at')->whereNull('vehicles.deleted_at')
            ->select(['vehicle_media.id', 'vehicle_media.phash', 'vehicle_media.vehicle_id'])
            ->chunkById(500, function ($rows) use ($hash, &$match): bool {
                foreach ($rows as $row) {
                    $distance = ImageHash::distance($hash, (string) $row->phash);
                    if ($distance <= ImageHash::MAX_DISTANCE) {
                        $match = [(int) $row->vehicle_id, $distance];

                        return false;
                    }
                }

                return true;
            }, 'vehicle_media.id', 'id');

        if ($match !== null) {
            $other = Vehicle::withoutGlobalScopes()->with('lot')->find($match[0]);
            $this->flag($vehicle, FraudSignalType::DuplicatePhoto, $other, ['other_lot' => $other?->lot->name, 'distance' => $match[1]]);
        }
    }

    private function lowPrice(Vehicle $vehicle): void
    {
        $guide = $vehicle->price ? PricingGuide::for($vehicle) : null;

        if ($guide === null || $vehicle->price >= $guide['median'] * self::LOW_PRICE_RATIO) {
            return;
        }

        $this->flag($vehicle, FraudSignalType::LowPrice, null, [
            'below_percent' => (int) round((1 - $vehicle->price / $guide['median']) * 100),
            'median' => $guide['median'],
            'price' => $vehicle->price,
        ]);
    }

    private function burst(Vehicle $vehicle): void
    {
        $lot = $vehicle->lot;

        if (! $lot instanceof Lot || $lot->created_at === null || $lot->created_at->lt(now()->subDays(self::NEW_LOT_DAYS))) {
            return;
        }

        $count = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('listed_at', '>=', now()->subDay())->count();

        if ($count > self::BURST_LIMIT) {
            $this->flag($vehicle, FraudSignalType::ListingBurst, null, ['count' => $count]);
        }
    }

    /** @param array<string, mixed> $details */
    private function flag(Vehicle $vehicle, FraudSignalType $type, ?Vehicle $related, array $details): void
    {
        // One signal per car and type; one an admin already cleared is not reopened.
        FraudSignal::firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'type' => $type],
            ['lot_id' => $vehicle->lot_id, 'related_vehicle_id' => $related?->id, 'details' => $details],
        );
    }
}
