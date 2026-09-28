<?php

namespace App\Domain\Sharing\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/** QR codes drawn with GD, so no Imagick is needed on the server. */
final class QrCode
{
    /** PNG bytes: dark modules on white with a two-module quiet zone. */
    public static function png(string $content, int $size = 300, string $dark = '#16302B'): string
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M(), 'UTF-8')->getMatrix();
        $modules = $matrix->getWidth();
        $quiet = 2;
        $scale = max(1, intdiv($size, $modules + 2 * $quiet));
        $pixels = ($modules + 2 * $quiet) * $scale;

        $image = imagecreatetruecolor($pixels, $pixels);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        [$r, $g, $b] = sscanf($dark, '#%02x%02x%02x');
        $ink = (int) imagecolorallocate($image, (int) $r, (int) $g, (int) $b);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $quiet) * $scale;
                    $top = ($y + $quiet) * $scale;
                    imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $ink);
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
