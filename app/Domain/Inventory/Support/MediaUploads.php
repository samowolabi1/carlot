<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where browsers send original photos. On an S3-compatible disk (Cloudflare R2) the
 * browser PUTs straight to the bucket with a pre-signed URL; on a local disk (Laragon,
 * tests) it posts the file to the app instead. Either way it ends with an upload key
 * that AttachVehicleMedia turns into a VehicleMedia row.
 */
class MediaUploads
{
    public const MAX_BYTES = 12 * 1024 * 1024;

    public const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return config('lotlink.upload_disk');
    }

    public function isDirect(): bool
    {
        return config("filesystems.disks.{$this->diskName()}.driver") === 's3';
    }

    public function newKey(Vehicle $vehicle, string $mimeType): string
    {
        $extension = self::MIME_TYPES[$mimeType] ?? 'jpg';

        return "uploads/{$vehicle->ulid}/".Str::uuid()->toString().".{$extension}";
    }

    /** Keys are only valid for the vehicle they were issued for. */
    public function keyBelongsTo(string $key, Vehicle $vehicle): bool
    {
        return (bool) preg_match('#^uploads/'.preg_quote($vehicle->ulid, '#').'/[0-9a-f-]{36}\.(jpg|png|webp)$#', $key);
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>}
     */
    public function presign(string $key, string $mimeType): array
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = $this->disk();
        ['url' => $url, 'headers' => $headers] = $disk->temporaryUploadUrl($key, now()->addMinutes(15), ['ContentType' => $mimeType]);

        return [
            'method' => 'PUT',
            'url' => $url,
            'headers' => array_map(fn ($value) => is_array($value) ? implode(', ', $value) : (string) $value, $headers) + ['Content-Type' => $mimeType],
        ];
    }
}
