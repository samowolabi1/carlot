<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

enum LenderStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Rejected => 'Not approved',
        };
    }
}
