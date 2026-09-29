<?php

namespace App\Domain\Inventory\Support;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * 64-bit difference hash (dHash): small edits, re-compression and resizing keep it within a
 * few bits, so the same photo re-uploaded by another lot is spotted (TDD M14 fraud signals).
 */
final class ImageHash
{
    /** Bits that may differ for two photos to count as the same picture. */
    public const MAX_DISTANCE = 6;

    public static function dhash(ImageInterface $image): string
    {
        $small = (clone $image)->resize(9, 8)->greyscale();
        $bits = '';

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = $small->pickColor($x, $y)->toArray()[0];
                $right = $small->pickColor($x + 1, $y)->toArray()[0];
                $bits .= $left > $right ? '1' : '0';
            }
        }

        return implode('', array_map(fn (string $nibble) => dechex((int) bindec($nibble)), str_split($bits, 4)));
    }

    public static function distance(string $a, string $b): int
    {
        if (strlen($a) !== 16 || strlen($b) !== 16) {
            return 64;
        }

        $distance = 0;
        for ($i = 0; $i < 16; $i++) {
            $distance += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }

        return $distance;
    }
}
