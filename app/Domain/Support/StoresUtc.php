<?php

namespace App\Domain\Support;

use Illuminate\Support\Carbon;

/**
 * Stores every date as UTC. Laravel otherwise writes a timezone-aware value's wall-clock
 * time, so 10:00 in Lagos would be saved as 10:00 UTC.
 */
trait StoresUtc
{
    public function fromDateTime($value): ?string
    {
        return parent::fromDateTime($value instanceof \DateTimeInterface ? Carbon::instance($value)->utc() : $value);
    }
}
