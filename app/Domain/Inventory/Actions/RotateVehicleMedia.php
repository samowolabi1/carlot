<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Support\ImageHash;
use App\Domain\Sharing\Jobs\RenderShareCard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Turns a photo a quarter turn, for pictures taken sideways. The renditions are rebuilt from the
 * largest one and saved under new names, because the CDN caches photo files forever.
 */
class RotateVehicleMedia
{
    public function run(VehicleMedia $media, int $degrees): VehicleMedia
    {
        if (! in_array($degrees, [90, -90, 180], true)) {
            throw ValidationException::withMessages(['degrees' => 'Rotate by 90 degrees either way, or 180.']);
        }

        $disk = Storage::disk(config('lotlink.media_disk'));
        $old = $media->variantPaths();

        if ($media->status !== MediaStatus::Ready || $old === [] || ! $disk->exists($old[VehicleMedia::WIDTHS[0]])) {
            throw ValidationException::withMessages(['degrees' => 'This photo is still processing. Try again in a moment.']);
        }

        $vehicle = $media->vehicle()->withoutGlobalScopes()->firstOrFail();
        // Intervention rotates anticlockwise for positive angles; the UI speaks clockwise.
        $image = (new ImageManager(new Driver))->read((string) $disk->get($old[VehicleMedia::WIDTHS[0]]))->rotate(-$degrees);
        $stem = "vehicles/{$vehicle->ulid}/{$media->ulid}-".Str::lower(Str::random(6));
        $size = null;
        $new = [];

        foreach (VehicleMedia::WIDTHS as $width) {
            $variant = (clone $image)->scaleDown(width: $width);
            $size ??= [$variant->width(), $variant->height()];
            $new[$width] = "{$stem}-{$width}.webp";
            $disk->put($new[$width], (string) $variant->toWebp(quality: 80), ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);
        }

        $media->update([
            'path' => $new[VehicleMedia::WIDTHS[0]],
            'thumb_path' => $new[VehicleMedia::WIDTHS[2]],
            'width' => $size[0],
            'height' => $size[1],
            'phash' => ImageHash::dhash($image),
        ]);
        $disk->delete(array_values($old));

        if ($media->is_cover) {
            RenderShareCard::refresh($vehicle->id);
        }

        return $media;
    }
}
