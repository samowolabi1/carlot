<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Enums\MediaType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $ulid
 * @property int $vehicle_id
 * @property MediaType $type
 * @property MediaStatus $status
 * @property string|null $original_path
 * @property string|null $path
 * @property string|null $thumb_path
 * @property int|null $width
 * @property int|null $height
 * @property int $sort_order
 * @property bool $is_cover
 * @property string|null $error
 */
class VehicleMedia extends Model
{
    use HasUlids;

    /** Widths of the WebP renditions; the largest is `path`, the smallest `thumb_path`. */
    public const WIDTHS = [1600, 800, 400];

    protected $fillable = ['vehicle_id', 'type', 'status', 'original_path', 'path', 'thumb_path', 'width', 'height', 'sort_order', 'is_cover', 'error'];

    protected $hidden = ['id', 'vehicle_id', 'original_path'];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'is_cover' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public static function variantPath(string $vehicleUlid, string $mediaUlid, int $width): string
    {
        return "vehicles/{$vehicleUlid}/{$mediaUlid}-{$width}.webp";
    }

    /** @return array<int, string> width => URL, for srcset */
    public function urls(): array
    {
        if ($this->status !== MediaStatus::Ready || $this->path === null) {
            return [];
        }

        $disk = Storage::disk(config('lotlink.media_disk'));
        $urls = [];

        foreach (self::WIDTHS as $width) {
            $urls[$width] = $disk->url(str_replace('-'.self::WIDTHS[0].'.webp', "-{$width}.webp", $this->path));
        }

        return $urls;
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? Storage::disk(config('lotlink.media_disk'))->url($this->thumb_path) : null;
    }
}
