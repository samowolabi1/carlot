<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum VehicleCondition: string
{
    use HasOptions;

    case New = 'new';
    case ForeignUsed = 'foreign_used';
    case LocallyUsed = 'locally_used';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Brand new',
            self::ForeignUsed => 'Foreign used (Tokunbo)',
            self::LocallyUsed => 'Nigerian used',
        };
    }
}
