<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum CostType: string
{
    use HasOptions;

    case Purchase = 'purchase';
    case Clearing = 'clearing';
    case Repair = 'repair';
    case Transport = 'transport';
    case Cleaning = 'cleaning';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Clearing => 'Clearing and duty',
            self::Repair => 'Repairs',
            self::Transport => 'Transport',
            self::Cleaning => 'Cleaning',
            self::Other => 'Other',
        };
    }
}
