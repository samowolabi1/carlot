<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum Transmission: string
{
    use HasOptions;

    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
