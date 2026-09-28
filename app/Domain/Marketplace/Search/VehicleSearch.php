<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Pagination\LengthAwarePaginator;

interface VehicleSearch
{
    /**
     * Marketplace cars matching the criteria, with make, model, lot and cover loaded.
     *
     * @return LengthAwarePaginator<int, Vehicle>
     */
    public function search(SearchCriteria $criteria): LengthAwarePaginator;
}
