<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Search straight from MySQL, for local development (no Meilisearch needed) and as
 * the reference behaviour the Meilisearch engine is tested against.
 */
class DatabaseVehicleSearch implements VehicleSearch
{
    private const KM_PER_DEGREE = 111.32;

    public function search(SearchCriteria $criteria): LengthAwarePaginator
    {
        $query = Vehicle::query()
            ->marketplace()
            ->select('vehicles.*')
            ->join('lots', 'lots.id', '=', 'vehicles.lot_id')
            ->with(['make', 'model', 'lot', 'cover']);

        $this->applyFilters($query, $criteria);
        $this->applySort($query, $criteria);

        return $query->paginate($criteria->perPage, ['vehicles.*'], 'page', $criteria->page);
    }

    public function sponsored(SearchCriteria $criteria, int $limit = 3): Collection
    {
        $query = Vehicle::query()
            ->marketplace()
            ->select('vehicles.*')
            ->join('lots', 'lots.id', '=', 'vehicles.lot_id')
            ->with(['make', 'model', 'lot', 'cover'])
            ->where('vehicles.spotlight_until', '>', now());

        $this->applyFilters($query, $criteria);

        return $query->inRandomOrder()->limit($limit)->get();
    }

    /** Whether one car matches the filters (saved-search alerts; the database is the reference). */
    public function matches(SearchCriteria $criteria, Vehicle $vehicle): bool
    {
        $query = Vehicle::query()->marketplace()->join('lots', 'lots.id', '=', 'vehicles.lot_id')->where('vehicles.id', $vehicle->id);
        $this->applyFilters($query, $criteria);

        return $query->exists();
    }

    /** @param Builder<Vehicle> $query */
    private function applyFilters(Builder $query, SearchCriteria $c): void
    {
        if ($c->query !== null) {
            foreach (preg_split('/\s+/', $c->query) ?: [] as $word) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
                $query->where(fn (Builder $q) => $q
                    ->where('vehicles.trim', 'like', $like)
                    ->orWhere('vehicles.year', $word)
                    ->orWhere('lots.name', 'like', $like)
                    ->orWhereHas('make', fn (Builder $q) => $q->where('name', 'like', $like))
                    ->orWhereHas('model', fn (Builder $q) => $q->where('name', 'like', $like)));
            }
        }

        $query
            ->when($c->makeIds, fn (Builder $q) => $q->whereIn('vehicles.make_id', $c->makeIds))
            ->when($c->modelId, fn (Builder $q) => $q->where('vehicles.vehicle_model_id', $c->modelId))
            ->when($c->bodyTypes, fn (Builder $q) => $q->whereIn('vehicles.body_type', $c->bodyTypes))
            ->when($c->conditions, fn (Builder $q) => $q->whereIn('vehicles.condition', $c->conditions))
            ->when($c->transmission, fn (Builder $q) => $q->where('vehicles.transmission', $c->transmission))
            ->when($c->fuels, fn (Builder $q) => $q->whereIn('vehicles.fuel', $c->fuels))
            ->when($c->priceMin, fn (Builder $q) => $q->where('vehicles.price', '>=', $c->priceMin))
            ->when($c->priceMax, fn (Builder $q) => $q->where('vehicles.price', '<=', $c->priceMax))
            ->when($c->yearMin, fn (Builder $q) => $q->where('vehicles.year', '>=', $c->yearMin))
            ->when($c->yearMax, fn (Builder $q) => $q->where('vehicles.year', '<=', $c->yearMax))
            ->when($c->mileageMax, fn (Builder $q) => $q->where('vehicles.mileage_km', '<=', $c->mileageMax))
            ->when($c->city, fn (Builder $q) => $q->where('lots.city', $c->city))
            ->when($c->state, fn (Builder $q) => $q->where('lots.state', $c->state))
            ->when($c->lotId, fn (Builder $q) => $q->where('vehicles.lot_id', $c->lotId));

        if ($c->hasLocation() && $c->radiusKm !== null) {
            // Bounding box first (uses the lat/lng columns), then the exact radius.
            $dLat = $c->radiusKm / self::KM_PER_DEGREE;
            $dLng = $c->radiusKm / (self::KM_PER_DEGREE * max(0.01, cos(deg2rad($c->lat))));

            $query->whereBetween('lots.latitude', [$c->lat - $dLat, $c->lat + $dLat])
                ->whereBetween('lots.longitude', [$c->lng - $dLng, $c->lng + $dLng])
                ->whereRaw($this->distanceSquaredSql($c).' <= ?', [$c->radiusKm ** 2]);
        }
    }

    /** @param Builder<Vehicle> $query */
    private function applySort(Builder $query, SearchCriteria $c): void
    {
        match ($c->sort) {
            'price_asc' => $query->orderBy('vehicles.price'),
            'price_desc' => $query->orderByDesc('vehicles.price'),
            'year_desc' => $query->orderByDesc('vehicles.year'),
            'mileage_asc' => $query->orderBy('vehicles.mileage_km'),
            'nearest' => $query->whereNotNull('lots.latitude')->orderByRaw($this->distanceSquaredSql($c)),
            default => $query->orderByDesc('vehicles.listed_at'),
        };

        $query->orderByDesc('vehicles.id');
    }

    /**
     * Squared distance in km² using an equirectangular projection: portable SQL (MySQL
     * and SQLite, no trig functions) and accurate to well under 1% at city distances.
     */
    private function distanceSquaredSql(SearchCriteria $c): string
    {
        $lat = (float) $c->lat;
        $lng = (float) $c->lng;
        $k = self::KM_PER_DEGREE;
        $kLng = $k * cos(deg2rad($lat));

        return sprintf('(((lots.latitude - %F) * %F) * ((lots.latitude - %F) * %F) + ((lots.longitude - %F) * %F) * ((lots.longitude - %F) * %F))', $lat, $k, $lat, $k, $lng, $kLng, $lng, $kLng);
    }
}
