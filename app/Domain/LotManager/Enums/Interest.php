<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum Interest: string
{
    use HasOptions;

    case Browsing = 'browsing';
    case Serious = 'serious';
    case ReadyToBuy = 'ready_to_buy';

    public function label(): string
    {
        return match ($this) {
            self::Browsing => 'Just looking',
            self::Serious => 'Serious',
            self::ReadyToBuy => 'Ready to buy',
        };
    }
}
