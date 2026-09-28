<?php

namespace App\Domain\Deals\Enums;

enum TradeInStatus: string
{
    case Submitted = 'submitted';
    case Valued = 'valued';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting for a valuation',
            self::Valued => 'Valued',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
        };
    }
}
