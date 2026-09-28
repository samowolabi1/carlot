<?php

namespace App\Domain\Lots\Concerns;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level tenancy for lot-owned models: sets lot_id on create and, while a dealer
 * context is active, limits every query to the current lot.
 */
trait BelongsToLot
{
    public static function bootBelongsToLot(): void
    {
        static::addGlobalScope('lot', function (Builder $query): void {
            $current = app(CurrentLot::class);

            if ($current->has()) {
                $query->where($query->qualifyColumn('lot_id'), $current->id());
            }
        });

        static::creating(function ($model): void {
            $current = app(CurrentLot::class);

            if ($model->lot_id === null && $current->has()) {
                $model->lot_id = $current->id();
            }
        });
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
