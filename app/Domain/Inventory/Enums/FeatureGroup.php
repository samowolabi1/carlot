<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum FeatureGroup: string
{
    use HasOptions;

    case Comfort = 'comfort';
    case Safety = 'safety';
    case Tech = 'tech';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
