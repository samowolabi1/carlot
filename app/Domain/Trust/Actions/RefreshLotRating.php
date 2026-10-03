<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Review;

/** Lot rating = mean of visible reviews (TDD M14), cached on the seller for listings and search. */
class RefreshLotRating
{
    public static function run(int $lotId): void
    {
        $stats = Review::withoutGlobalScopes()->where('lot_id', $lotId)->where('status', ReviewStatus::Visible)
            ->toBase()->selectRaw('count(*) as total, avg(rating) as mean')->first();

        Lot::whereKey($lotId)->update([
            'reviews_count' => (int) ($stats->total ?? 0),
            'rating' => $stats && $stats->total > 0 ? round((float) $stats->mean, 2) : null,
        ]);
    }
}
