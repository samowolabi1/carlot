<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

enum LenderRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Officer = 'officer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Officer => 'Loan officer',
        };
    }
}
