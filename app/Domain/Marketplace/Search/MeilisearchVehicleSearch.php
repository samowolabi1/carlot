<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Meilisearch\Exceptions\ExceptionInterface as MeilisearchException;

/**
 * Production search (TDD M4): the Meilisearch "vehicles" index holds only marketplace
 * cars; results are hydrated from MySQL in hit order. If Meilisearch is down or rejects
 * the query, buyers get the same search from MySQL (slower, but the marketplace stays up).
 */
class MeilisearchVehicleSearch implements VehicleSearch
{
    public function __construct(private readonly DatabaseVehicleSearch $fallback) {}

    public function search(SearchCriteria $criteria): LengthAwarePaginator
    {
        try {
            return Vehicle::search($criteria->query ?? '')
                ->options(array_filter([
                    'filter' => $this->filters($criteria),
                    'sort' => $this->sort($criteria),
                ]))
                ->query(fn (Builder $query) => $query->withoutGlobalScope('lot')->with(['make', 'model', 'lot', 'cover']))
                ->paginate($criteria->perPage, 'page', $criteria->page);
        } catch (MeilisearchException $e) {
            report($e);

            return $this->fallback->search($criteria);
        }
    }

    public function sponsored(SearchCriteria $criteria, int $limit = 3): Collection
    {
        // Meilisearch has no random order, so a handful are fetched and shuffled here.
        // Sponsored cars are extra: if Meilisearch is down or its settings are out of date (spotlight_until not yet
        // filterable because scout:sync-index-settings wasn't run), report it and show the results without them.
        try {
            $ids = Vehicle::search($criteria->query ?? '')
                ->options(['filter' => [...$this->filters($criteria), 'spotlight_until > '.now()->getTimestamp()]])
                ->take(20)
                ->keys()
                ->shuffle()
                ->take($limit)
                ->all();
        } catch (MeilisearchException $e) {
            report($e);

            return new Collection;
        }

        return Vehicle::query()->withoutGlobalScope('lot')->with(['make', 'model', 'lot', 'cover'])->whereIn('id', $ids)->get()
            ->sortBy(fn (Vehicle $v) => array_search($v->id, $ids, false))->values();
    }

    /** @return list<string> Meilisearch filter expressions, ANDed together */
    public function filters(SearchCriteria $c): array
    {
        $in = fn (string $field, array $values) => $field.' IN ['.implode(', ', array_map(fn ($v) => is_int($v) ? $v : json_encode($v), $values)).']';

        $filters = [];
        $c->makeIds && $filters[] = $in('make_id', $c->makeIds);
        $c->modelIds && $filters[] = $in('vehicle_model_id', $c->modelIds);
        $c->bodyTypes && $filters[] = $in('body_type', $c->bodyTypes);
        $c->conditions && $filters[] = $in('condition', $c->conditions);
        $c->transmission && $filters[] = 'transmission = '.json_encode($c->transmission);
        $c->fuels && $filters[] = $in('fuel', $c->fuels);
        $c->drivetrains && $filters[] = $in('drivetrain', $c->drivetrains);
        $c->colours && $filters[] = $in('colour', $c->colours);
        $c->priceMin && $filters[] = "price >= {$c->priceMin}";
        $c->priceMax && $filters[] = "price <= {$c->priceMax}";
        $c->yearMin && $filters[] = "year >= {$c->yearMin}";
        $c->yearMax && $filters[] = "year <= {$c->yearMax}";
        $c->mileageMax && $filters[] = "mileage_km <= {$c->mileageMax}";
        $c->city && $filters[] = 'city = '.json_encode($c->city);
        $c->state && $filters[] = 'state = '.json_encode($c->state);
        $c->lotId && $filters[] = "lot_id = {$c->lotId}";
        foreach ($c->featureIds as $featureId) {
            $filters[] = "feature_ids = {$featureId}"; // an array attribute: matches when it contains the id
        }
        foreach ($c->extras as $extra) {
            $filter = match ($extra) {
                'loans' => 'lot_loans = true',
                'trade_ins' => 'lot_trade_ins = true',
                'offers' => 'lot_offers = true',
                'negotiable' => 'negotiable = true',
                'inspected' => 'inspected = true',
                'verified_lot' => 'lot_verified = true',
                'duty_paid' => 'duty_status = "paid"',
                'registered' => 'registered = true',
                default => null,
            };
            $filter !== null && $filters[] = $filter;
        }

        if ($c->hasLocation() && $c->radiusKm !== null) {
            $filters[] = sprintf('_geoRadius(%F, %F, %d)', $c->lat, $c->lng, $c->radiusKm * 1000);
        }

        return $filters;
    }

    /** @return list<string> */
    public function sort(SearchCriteria $c): array
    {
        return match ($c->sort) {
            'price_asc' => ['price:asc'],
            'price_desc' => ['price:desc'],
            'year_desc' => ['year:desc'],
            'mileage_asc' => ['mileage_km:asc'],
            'nearest' => [sprintf('_geoPoint(%F, %F):asc', $c->lat, $c->lng)],
            // With a text query, relevance first; otherwise newest listings first.
            default => $c->query !== null ? [] : ['listed_at:desc'],
        };
    }
}
