<?php

namespace App\Domain\Support;

/**
 * The states lots can be in (config('lotlink.regions'): Nigeria's 36 states and the FCT). Free text
 * from maps and old records ("Lagos State", "Abuja", "Federal Capital Territory") maps onto the list.
 */
final class Regions
{
    /** @return list<string> */
    public static function all(): array
    {
        /** @var list<string> $regions */
        $regions = config('lotlink.regions', []);

        return $regions;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $r) => ['value' => $r, 'label' => $r === 'FCT' ? 'FCT (Abuja)' : $r], self::all());
    }

    /** The list's spelling of a state, or null if it isn't one. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $clean = preg_replace('/\s+state$/i', '', trim($input)) ?? '';
        $aliases = ['abuja' => 'FCT', 'federal capital territory' => 'FCT', 'fct abuja' => 'FCT', 'fct (abuja)' => 'FCT', 'akwa-ibom' => 'Akwa Ibom', 'nassarawa' => 'Nasarawa'];
        $clean = $aliases[mb_strtolower($clean)] ?? $clean;

        foreach (self::all() as $region) {
            if (strcasecmp($region, $clean) === 0) {
                return $region;
            }
        }

        return null;
    }
}
