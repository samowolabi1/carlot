<?php

namespace App\Domain\Billing\Support;

use App\Domain\Lots\Models\Lot;

/** Paystack needs an email for every charge; most lots sign in by phone only. */
final class BillingEmail
{
    public static function for(Lot $lot): string
    {
        $owner = $lot->owner()->first();

        return $owner?->email ?: ($lot->email ?: 'lot-'.$lot->ulid.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'lotlink.app'));
    }
}
