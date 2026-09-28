<?php

namespace App\Domain\Inventory\Enums;

use App\Domain\Support\HasOptions;

enum DutyStatus: string
{
    use HasOptions;

    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case NotApplicable = 'na';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Customs duty paid',
            self::Unpaid => 'Duty not yet paid',
            self::NotApplicable => 'Not applicable',
        };
    }
}
