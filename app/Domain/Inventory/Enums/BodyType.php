<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum BodyType: string
{
    use HasOptions;

    case Sedan = 'sedan';
    case Suv = 'suv';
    case Hatchback = 'hatchback';
    case Pickup = 'pickup';
    case Van = 'van';
    case Coupe = 'coupe';
    case Wagon = 'wagon';
    case Convertible = 'convertible';
    case Bus = 'bus';
    case Truck = 'truck';

    public function label(): string
    {
        return match ($this) {
            self::Suv => 'SUV',
            self::Van => 'Van / minivan',
            default => ucfirst($this->value),
        };
    }
}
