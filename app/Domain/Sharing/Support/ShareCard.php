<?php

namespace App\Domain\Sharing\Support;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Sharing\Actions\CreateShareLink;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Support\PhoneNumber;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use Throwable;

/**
 * Branded images for sharing a car (TDD M9): 1080×1080 for feeds and 1080×1920 for
 * WhatsApp Status and Stories. Cover photo, price, title, key specs, the seller and a QR
 * code that opens the car. Stored on the media disk under a content hash, so a changed
 * price or cover gives new URLs and caches never show stale prices.
 */
class ShareCard
{
    /** Bump to re-render every card after a layout change. */
    private const VERSION = 1;

    private const FOREST = '#16302B';

    private const PEACH = '#FDBA8C';

    private const MIST = '#B9CCC6';

    private const CLAY = '#C2410C';

    public const SIZES = ['square' => [1080, 1080], 'story' => [1080, 1920]];

    public function __construct(private readonly CreateShareLink $links) {}

    /** What the card shows; any change means a new card. */
    public function hash(Vehicle $vehicle): string
    {
        $lot = $vehicle->lot;

        return sha1(json_encode([
            self::VERSION, $vehicle->title(), $vehicle->price, $vehicle->mileage_km, $vehicle->transmission?->value, $vehicle->fuel?->value,
            $vehicle->cover?->ulid, $lot->name, $lot->phone, $lot->logo_path,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{square: string, story: string}|null public URLs, if rendered */
    public function urls(Vehicle $vehicle): ?array
    {
        if ($vehicle->share_card_hash === null) {
            return null;
        }

        return [
            'square' => $this->disk()->url($this->path($vehicle, 'square')),
            'story' => $this->disk()->url($this->path($vehicle, 'story')),
        ];
    }

    public function path(Vehicle $vehicle, string $size, ?string $hash = null): string
    {
        return "share-cards/{$vehicle->ulid}/".substr($hash ?? (string) $vehicle->share_card_hash, 0, 12)."-{$size}.png";
    }

    /** Renders and stores both sizes; returns the hash they were stored under. */
    public function render(Vehicle $vehicle): string
    {
        $vehicle->loadMissing(['make', 'model', 'lot', 'cover']);
        $hash = $this->hash($vehicle);
        $disk = $this->disk();
        $qr = $this->links->run($vehicle, SharePlatform::Qr)->url();
        $photo = $this->photo($vehicle);

        foreach (self::SIZES as $size => [$width, $height]) {
            $image = $this->draw($vehicle, $width, $height, $photo, $qr);
            $disk->put($this->path($vehicle, $size, $hash), (string) $image->toPng(), [
                'visibility' => 'public', 'ContentType' => 'image/png', 'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
        }

        // Old cards for this car (previous prices) are removed.
        foreach ($disk->files("share-cards/{$vehicle->ulid}") as $file) {
            if (! str_starts_with(basename($file), substr($hash, 0, 12))) {
                $disk->delete($file);
            }
        }

        return $hash;
    }

    private function draw(Vehicle $vehicle, int $width, int $height, ?string $photo, string $qrUrl): ImageInterface
    {
        $story = $height > $width;
        $manager = new ImageManager(new Driver);
        $photoHeight = $story ? 1000 : 700;
        $image = $manager->create($width, $height)->fill(self::FOREST);

        // Photo, or a plain panel when there isn't one.
        if ($photo !== null) {
            $image->place($manager->read($photo)->cover($width, $photoHeight));
        } else {
            $image->drawRectangle(0, 0, fn ($r) => $r->size($width, $photoHeight)->background('#E9E5DC'));
        }

        // Fade the photo into the panel.
        $fade = 260;
        for ($i = 0; $i < $fade; $i++) {
            $alpha = round(($i / $fade) ** 1.6, 3);
            $image->drawRectangle(0, $photoHeight - $fade + $i, fn ($r) => $r->size($width, 1)->background("rgba(22, 48, 43, {$alpha})"));
        }

        // CarYard wordmark, top right.
        $wordmark = $this->measure('CarYard', 'bold', 38);
        $image->drawRectangle($width - 36 - $wordmark - 48, 36, fn ($r) => $r->size($wordmark + 48, 64)->background('rgba(22, 48, 43, 0.85)'));
        $this->text($image, 'Car', $width - 36 - $wordmark - 24, 68, 'bold', 38, '#FFFFFF', 'left', 'middle');
        $this->text($image, 'Yard', $width - 36 - $wordmark - 24 + $this->measure('Car', 'bold', 38), 68, 'bold', 38, '#F28C4B', 'left', 'middle');

        $pad = 64;
        $qrSize = $story ? 300 : 230;
        $textWidth = $width - 2 * $pad - $qrSize - 40;
        $lot = $vehicle->lot;
        $specs = implode(' · ', array_filter([
            $vehicle->mileage_km !== null ? number_format($vehicle->mileage_km).' km' : null,
            $vehicle->transmission?->label(),
            $vehicle->fuel?->label(),
        ]));
        $lotLine = $lot->name.($lot->phone ? ' · '.PhoneNumber::display($lot->phone) : '');

        $y = $photoHeight + ($story ? 40 : 20);
        $title = $this->fit($vehicle->title(), 'semibold', $story ? 60 : 48, $story ? $width - 2 * $pad : $textWidth);
        $this->text($image, $title, $pad, $y, 'semibold', $story ? 60 : 48, '#FFFFFF');
        $y += $story ? 96 : 72;
        $this->text($image, (string) $vehicle->formattedPrice(), $pad, $y, 'bold', $story ? 116 : 84, self::PEACH);
        $y += $story ? 170 : 124;
        if ($specs !== '') {
            $this->text($image, $this->fit($specs, 'regular', $story ? 42 : 32, $textWidth), $pad, $y, 'regular', $story ? 42 : 32, self::MIST);
            $y += $story ? 70 : 52;
        }

        // Lot badge: logo if there is one, else initials.
        $badge = $story ? 72 : 56;
        $logo = $this->logo($lot->logo_path);
        if ($logo !== null) {
            $image->place($manager->read($logo)->cover($badge, $badge), 'top-left', $pad, $y);
        } else {
            $image->drawRectangle($pad, $y, fn ($r) => $r->size($badge, $badge)->background(self::CLAY));
            $this->text($image, $lot->initials(), $pad + intdiv($badge, 2), $y + intdiv($badge, 2), 'bold', $story ? 30 : 24, '#FFFFFF', 'center', 'middle');
        }
        $lotWidth = ($story ? $width - 2 * $pad : $textWidth) - $badge - 20;
        $this->text($image, $this->fit($lotLine, 'semibold', $story ? 38 : 30, $lotWidth), $pad + $badge + 20, $y + intdiv($badge, 2), 'semibold', $story ? 38 : 30, '#FFFFFF', 'left', 'middle');

        // QR code that opens the car, bottom right.
        $qrTop = $height - $pad - $qrSize;
        $image->drawRectangle($width - $pad - $qrSize - 12, $qrTop - 12, fn ($r) => $r->size($qrSize + 24, $qrSize + 24)->background('#FFFFFF'));
        $image->place($manager->read(QrCode::png($qrUrl, $qrSize, self::FOREST))->resize($qrSize, $qrSize), 'top-left', $width - $pad - $qrSize, $qrTop);

        if ($story) {
            $middle = $qrTop + intdiv($qrSize, 2);
            $this->text($image, 'Find it on CarYard', $pad, $middle - 50, 'semibold', 44, '#FFFFFF', 'left', 'middle');
            $this->text($image, 'Scan the code to see every photo', $pad, $middle + 14, 'regular', 32, self::MIST, 'left', 'middle');
            $this->text($image, 'and book a viewing.', $pad, $middle + 60, 'regular', 32, self::MIST, 'left', 'middle');
        }

        return $image;
    }

    private function text(ImageInterface $image, string $text, int $x, int $y, string $weight, int $size, string $color, string $align = 'left', string $valign = 'top'): void
    {
        $image->text($text, $x, $y, function (FontFactory $font) use ($weight, $size, $color, $align, $valign): void {
            $font->filename(self::font($weight));
            $font->size($size);
            $font->color($color);
            $font->align($align);
            $font->valign($valign);
        });
    }

    /** Shortens text with an ellipsis until it fits. */
    private function fit(string $text, string $weight, int $size, int $maxWidth): string
    {
        if ($this->measure($text, $weight, $size) <= $maxWidth) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->measure($text.'…', $weight, $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function measure(string $text, string $weight, int $size): int
    {
        // GD's imagettfbbox works in points; Intervention sizes text in pixels (pt × 0.75).
        $box = imagettfbbox($size * 0.75, 0, self::font($weight), $text);

        return $box === false ? 0 : abs($box[2] - $box[0]);
    }

    /** Prices need the ₦ sign, which only the heading font has. */
    private static function font(string $weight): string
    {
        return resource_path(match ($weight) {
            'bold' => 'fonts/BricolageGrotesque-Bold.ttf',
            'semibold' => 'fonts/DMSans-SemiBold.ttf',
            default => 'fonts/DMSans-Regular.ttf',
        });
    }

    private function photo(Vehicle $vehicle): ?string
    {
        $path = $vehicle->cover?->path;

        return $path ? $this->read($path) : null;
    }

    private function logo(?string $path): ?string
    {
        return $path ? $this->read($path) : null;
    }

    private function read(string $path): ?string
    {
        try {
            return $this->disk()->get($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('lotlink.media_disk'));
    }
}
