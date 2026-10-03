<?php

namespace App\Domain\Lots\Support;

use App\Domain\Lots\Models\Lot;

/**
 * Holds the seller a seller request is acting on. SetCurrentLot sets it after checking
 * membership; BelongsToLot reads it to scope queries and stamp lot_id on new rows.
 */
class CurrentLot
{
    private ?Lot $lot = null;

    public function set(?Lot $lot): void
    {
        $this->lot = $lot;
    }

    public function get(): ?Lot
    {
        return $this->lot;
    }

    public function id(): ?int
    {
        return $this->lot?->getKey();
    }

    public function has(): bool
    {
        return $this->lot !== null;
    }
}
