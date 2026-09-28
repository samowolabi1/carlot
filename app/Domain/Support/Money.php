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

    /** "₦3.8m", "₦250k": for messages and tight spaces. */
    public static function compact(int $minor, string $currency = 'NGN'): string
    {
        $major = $minor / 100;
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return match (true) {
            $major >= 1_000_000 => $symbol.rtrim(rtrim(number_format($major / 1_000_000, 2), '0'), '.').'m',
            $major >= 1_000 => $symbol.rtrim(rtrim(number_format($major / 1_000, 1), '0'), '.').'k',
            default => $symbol.number_format($major),
        };
    }

    /** Whole currency units (naira) typed by a user → minor units. */
    public static function fromMajor(int|float|string $major): int
    {
        return (int) round(((float) $major) * 100);
    }
}
