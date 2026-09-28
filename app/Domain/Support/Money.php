<?php

namespace App\Domain\Support;

/**
 * Amounts are stored as integer minor units (kobo, pence) with an ISO currency code.
 */
final class Money
{
    private const SYMBOLS = ['NGN' => '₦', 'GBP' => '£', 'USD' => '$'];

    public static function format(?int $minor, string $currency = 'NGN'): ?string
    {
        if ($minor === null) {
            return null;
        }

        $major = intdiv($minor, 100);
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return $symbol.number_format($major);
    }

    /** Whole currency units (naira) typed by a user → minor units. */
    public static function fromMajor(int|float|string $major): int
    {
        return (int) round(((float) $major) * 100);
    }
}
