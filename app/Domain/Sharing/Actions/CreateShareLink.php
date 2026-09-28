<?php

namespace App\Domain\Sharing\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Sharing\Models\ShareLink;

class CreateShareLink
{
    /**
     * A tracked short link for a car or a lot (TDD M9). The same person sharing the same
     * thing to the same platform reuses one link, so counts aren't split. QR codes (on share
     * cards and stickers) share one link per car.
     */
    public function run(Vehicle|Lot $target, SharePlatform $platform, ?User $user = null): ShareLink
    {
        $attributes = [
            'vehicle_id' => $target instanceof Vehicle ? $target->id : null,
            'lot_id' => $target instanceof Vehicle ? $target->lot_id : $target->id,
            'platform' => $platform,
            'user_id' => $platform === SharePlatform::Qr ? null : $user?->id,
        ];

        if ($attributes['user_id'] === null && $platform !== SharePlatform::Qr) {
            return ShareLink::create($attributes);
        }

        return ShareLink::query()->where($attributes)->first() ?? ShareLink::create($attributes);
    }
}
