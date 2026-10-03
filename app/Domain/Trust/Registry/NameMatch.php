<?php

namespace App\Domain\Trust\Registry;

use Illuminate\Support\Str;

/**
 * How closely a seller's trading name matches its registered company name, 0–100. Words every company has
 * ("limited", "enterprises", "Nigeria"…) are ignored, so "AutoHub" matches "AUTOHUB MOTORS NIGERIA LIMITED".
 */
final class NameMatch
{
    private const FILLER = ['limited', 'ltd', 'plc', 'enterprise', 'enterprises', 'ent', 'nigeria', 'nig', 'company', 'co',
        'and', 'the', 'global', 'international', 'intl', 'ventures', 'venture', 'services', 'resources', 'group', 'integrated'];

    public static function score(string $tradingName, string $registeredName): int
    {
        $a = self::words($tradingName);
        $b = self::words($registeredName);
        if ($a === [] || $b === []) {
            return 0;
        }

        // Every word of the shorter name inside the longer one (in order or not) is a full match.
        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
        if (array_diff($short, $long) === []) {
            return 100;
        }

        similar_text(implode(' ', $a), implode(' ', $b), $percent);
        $shared = count(array_intersect($short, $long)) / count($short) * 100;

        return (int) round(max($percent, $shared));
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        $words = preg_split('/\s+/', Str::of($name)->ascii()->lower()->replace('&', ' and ')->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->value()) ?: [];

        return array_values(array_filter($words, fn (string $w) => $w !== '' && ! in_array($w, self::FILLER, true)));
    }
}
