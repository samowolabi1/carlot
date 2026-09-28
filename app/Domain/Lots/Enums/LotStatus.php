<?php

namespace App\Domain\Lots\Enums;

enum LotStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Active => 'Live',
            self::Suspended => 'Suspended',
        };
    }
}
