<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface VehicleSearch
{
    /**
     * Marketplace cars matching the criteria, with make, model, lot and cover loaded.
     *
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function search(SearchCriteria $criteria): LengthAwarePaginator;

    /**
     * Up to $limit spotlighted cars matching the same criteria, in random order, shown
     * first and labelled "Sponsored" (TDD M5: at most 3 per results page).
     *
     * @return Collection<int, Vehicle>
     */
    public function sponsored(SearchCriteria $criteria, int $limit = 3): Collection;
}
