<?php

namespace App\Domain\Inventory\Jobs;

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Support\ImageHash;
use App\Domain\Inventory\Support\MediaUploads;
use App\Domain\Sharing\Jobs\RenderShareCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Turns an uploaded original into WebP renditions at 1600, 800 and 400 px. Re-encoding
 * drops EXIF, so GPS location never reaches the public bucket.
 */
class ProcessVehicleMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId)
    {
        $this->onQueue('media');
    }

    public function handle(MediaUploads $uploads): void
    {
        $media = VehicleMedia::with('vehicle')->find($this->mediaId);

        if ($media === null || $media->status !== MediaStatus::Processing || $media->original_path === null) {
            return;
        }

        $source = $uploads->disk();
        $originalPath = $media->original_path;

        // Direct uploads bypass the app, so size and type are checked again here.
        if ($source->size($originalPath) > MediaUploads::MAX_BYTES) {
            $this->markFailed($media, 'Photo is larger than 12 MB.');

            return;
        }

        $mime = $source->mimeType($originalPath);

        if (! array_key_exists((string) $mime, MediaUploads::MIME_TYPES)) {
            $this->markFailed($media, 'Only JPEG, PNG and WebP photos are supported.');

            return;
        }

        try {
            $image = (new ImageManager(new Driver, autoOrientation: true))->read((string) $source->get($originalPath));
        } catch (Throwable) {
            $this->markFailed($media, 'We could not read that photo.');

            return;
        }

        $target = Storage::disk(config('lotlink.media_disk'));
        $vehicleUlid = $media->vehicle->ulid;
        $size = null;

        foreach (VehicleMedia::WIDTHS as $width) {
            $variant = (clone $image)->scaleDown(width: $width);
            $size ??= [$variant->width(), $variant->height()];
            $target->put(
                VehicleMedia::variantPath($vehicleUlid, $media->ulid, $width),
                (string) $variant->toWebp(quality: 80),
                ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'],
            );
        }

        $media->update([
            'status' => MediaStatus::Ready,
            'path' => VehicleMedia::variantPath($vehicleUlid, $media->ulid, VehicleMedia::WIDTHS[0]),
            'thumb_path' => VehicleMedia::variantPath($vehicleUlid, $media->ulid, VehicleMedia::WIDTHS[2]),
            'width' => $size[0],
            'height' => $size[1],
            'phash' => ImageHash::dhash($image),
            'original_path' => null,
            'error' => null,
        ]);

        $source->delete($originalPath);

        if ($media->is_cover) {
            RenderShareCard::refresh($media->vehicle_id);
        }
    }

    /** After the last retry: show the photo as failed so the dealer can remove it and try again. */
    public function failed(?Throwable $exception): void
    {
        VehicleMedia::whereKey($this->mediaId)->update(['status' => MediaStatus::Failed, 'error' => 'Processing failed. Remove the photo and add it again.']);
    }

    private function markFailed(VehicleMedia $media, string $reason): void
    {
        $media->update(['status' => MediaStatus::Failed, 'error' => $reason]);
    }
}
