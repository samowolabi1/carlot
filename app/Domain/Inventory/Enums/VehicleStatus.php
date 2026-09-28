<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum VehicleStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Hidden = 'hidden';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Statuses that count against the plan's listing limit. */
    public static function live(): array
    {
        return [self::Available, self::Reserved];
    }
}
