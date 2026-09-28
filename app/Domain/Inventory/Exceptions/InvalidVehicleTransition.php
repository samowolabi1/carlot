<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\VehicleStatus;
use RuntimeException;

class InvalidVehicleTransition extends RuntimeException
{
    public static function between(VehicleStatus $from, VehicleStatus $to): self
    {
        return new self("A {$from->value} car can't be marked {$to->value}.");
    }
}
