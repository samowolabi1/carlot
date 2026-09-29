<?php

namespace App\Domain\Advertising\Support;

use App\Domain\Advertising\Enums\AdPlacement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/** Banner creatives: cropped to the placement's size and re-encoded as WebP (drops EXIF and any payload). */
final class AdImage
{
    public const MAX_KB = 8192;

    public static function store(UploadedFile $file, AdPlacement $placement, string $campaignUlid): string
    {
        [$width, $height] = $placement->size();
        $image = (new ImageManager(new Driver, autoOrientation: true))->read((string) file_get_contents($file->getRealPath()))->cover($width, $height);
        $path = "ads/{$campaignUlid}/".Str::lower(Str::random(10)).'.webp';

        Storage::disk(config('lotlink.media_disk'))->put($path, (string) $image->toWebp(quality: 82), [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return $path;
    }
}
