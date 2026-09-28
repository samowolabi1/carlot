<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum Drivetrain: string
{
    use HasOptions;

    case Fwd = 'fwd';
    case Rwd = 'rwd';
    case Awd = 'awd';
    case FourWd = '4wd';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
