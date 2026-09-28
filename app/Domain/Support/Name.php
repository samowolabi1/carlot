<?php

namespace App\Domain\Support;

final class Name
{
    /** "Tunde Adebayo" → "Tunde A.", as the dealer designs show buyers. */
    public static function short(?string $name, string $fallback = 'Buyer'): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        if ($parts === [] || $parts[0] === '') {
            return $fallback;
        }

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $parts[0];
    }
}
