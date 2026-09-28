<?php

namespace App\Domain\Lots\Enums;

enum LotRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Sales = 'sales';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Roles an owner can hand out through an invitation. */
    public static function invitable(): array
    {
        return [self::Manager, self::Sales];
    }
}
