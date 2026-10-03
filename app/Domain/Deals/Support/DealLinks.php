<?php

namespace App\Domain\Deals\Support;

use App\Domain\Lots\Models\Lot;

/** Where deal notifications point. */
final class DealLinks
{
    /** The buyer's "Bookings and offers" page (design 19). */
    public static function buyer(): string
    {
        return route('bookings.index').'#offers';
    }

    /** The seller's Offers and trade-ins page (design D7) on a tab: offers, trade-ins or reservations. */
    public static function lot(Lot $lot, string $tab): string
    {
        return route('dealer.offers.index', [$lot->slug, 'tab' => $tab]);
    }
}
