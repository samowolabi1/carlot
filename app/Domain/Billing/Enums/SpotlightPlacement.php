<?php

namespace App\Domain\Billing\Enums;

enum SpotlightPlacement: string
{
    /** A car: first in matching search results ("Sponsored") and in the home carousel. */
    case Car = 'car';
    /** A lot: the "Featured lots" row on the home page. */
    case FeaturedLot = 'featured_lot';

    public function label(): string
    {
        return match ($this) {
            self::Car => 'Car spotlight',
            self::FeaturedLot => 'Featured lot',
        };
    }
}
