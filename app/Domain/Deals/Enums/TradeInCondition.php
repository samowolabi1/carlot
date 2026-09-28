<?php

namespace App\Domain\Deals\Enums;

use App\Domain\Support\HasOptions;

enum TradeInCondition: string
{
    use HasOptions;

    case Excellent = 'excellent';
    case Good = 'good';
    case Fair = 'fair';
    case NeedsWork = 'needs_work';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent: like new, no faults',
            self::Good => 'Good: minor scratches, runs well',
            self::Fair => 'Fair: dents or worn interior, runs fine',
            self::NeedsWork => 'Needs work: mechanical or body repairs',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent',
            self::Good => 'Good',
            self::Fair => 'Fair',
            self::NeedsWork => 'Needs work',
        };
    }
}
