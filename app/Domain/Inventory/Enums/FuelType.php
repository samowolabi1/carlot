<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum FuelType: string
{
    use HasOptions;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
    case Cng = 'cng';

    public function label(): string
    {
        return $this === self::Cng ? 'CNG' : ucfirst($this->value);
    }
}
