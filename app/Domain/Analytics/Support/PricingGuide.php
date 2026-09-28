<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;

/**
 * The pricing guide (TDD M15): median and range of the asking prices of available cars with
 * the same make, model and year ±1 across LotLink, shown with 5 or more comparables.
 */
final class PricingGuide
{
    public const MIN_COMPARABLES = 5;

    /** @return array{low: int, median: int, high: int, count: int}|null minor units */
    public static function for(Vehicle $vehicle): ?array
    {
        if ($vehicle->vehicle_model_id === null || $vehicle->year === null) {
            return null;
        }

        $prices = Vehicle::query()->marketplace()
            ->where('vehicles.status', VehicleStatus::Available)
            ->where('vehicles.vehicle_model_id', $vehicle->vehicle_model_id)
            ->whereBetween('vehicles.year', [$vehicle->year - 1, $vehicle->year + 1])
            ->where('vehicles.id', '!=', $vehicle->id)
            ->where('vehicles.price', '>', 0)
            ->pluck('vehicles.price')->map(fn ($p) => (int) $p)->sort()->values();

        if ($prices->count() < self::MIN_COMPARABLES) {
            return null;
        }

        return ['low' => (int) $prices->first(), 'median' => (int) $prices->median(), 'high' => (int) $prices->last(), 'count' => $prices->count()];
    }

    /** "above", "below" or null when a price sits inside the middle of the range. */
    public static function position(int $price, array $guide): ?string
    {
        return match (true) {
            $price > $guide['median'] * 1.15 => 'above',
            $price < $guide['median'] * 0.85 => 'below',
            default => null,
        };
    }
}
