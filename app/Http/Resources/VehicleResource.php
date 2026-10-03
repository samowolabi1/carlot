<?php

namespace App\Http\Resources;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Seller view of a vehicle, for the add/edit flow. Load make, model, media and features.
 *
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'title' => $this->title(),
            'status' => $this->status->value,
            'listed' => $this->listed_at !== null,
            'vin' => $this->vin,
            'make_id' => $this->make_id,
            'make' => $this->make?->name,
            'vehicle_model_id' => $this->vehicle_model_id,
            'model' => $this->model?->name,
            'year' => $this->year,
            'trim' => $this->trim,
            'body_type' => $this->body_type?->value,
            'mileage_km' => $this->mileage_km,
            'condition' => $this->condition?->value,
            'transmission' => $this->transmission?->value,
            'fuel' => $this->fuel?->value,
            'engine_cc' => $this->engine_cc,
            'drivetrain' => $this->drivetrain?->value,
            'colour' => $this->colour,
            'interior_colour' => $this->interior_colour,
            'duty_status' => $this->duty_status?->value,
            'registered' => $this->registered,
            'description' => $this->description,
            'feature_ids' => $this->whenLoaded('features', fn () => $this->features->pluck('id')),
            // Whole naira for the price input; stored in kobo.
            'price' => $this->price !== null ? intdiv($this->price, 100) : null,
            'price_formatted' => $this->formattedPrice(),
            'negotiable' => $this->negotiable,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn (VehicleMedia $m) => self::media($m))->values()),
        ];
    }

    /** @return array<string, mixed> */
    public static function media(VehicleMedia $media): array
    {
        return [
            'ulid' => $media->ulid,
            'status' => $media->status->value,
            'error' => $media->error,
            'is_cover' => $media->is_cover,
            'thumb_url' => $media->thumbUrl(),
            'urls' => $media->urls(),
        ];
    }
}
