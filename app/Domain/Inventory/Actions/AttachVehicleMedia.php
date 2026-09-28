<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Enums\MediaType;
use App\Domain\Inventory\Jobs\ProcessVehicleMedia;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Support\MediaUploads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachVehicleMedia
{
    public function __construct(private readonly MediaUploads $uploads) {}

    /** Registers an uploaded original and queues it for processing. */
    public function run(Vehicle $vehicle, string $key): VehicleMedia
    {
        if (! $this->uploads->keyBelongsTo($key, $vehicle) || ! $this->uploads->disk()->exists($key)) {
            throw ValidationException::withMessages(['key' => 'That upload was not found. Try adding the photo again.']);
        }

        $media = DB::transaction(function () use ($vehicle, $key): VehicleMedia {
            Vehicle::withoutGlobalScopes()->whereKey($vehicle->id)->lockForUpdate()->first();

            $count = $vehicle->media()->count();

            if ($count >= Vehicle::MAX_PHOTOS) {
                throw ValidationException::withMessages(['key' => 'A car can have up to '.Vehicle::MAX_PHOTOS.' photos.']);
            }

            return $vehicle->media()->create([
                'type' => MediaType::Photo,
                'status' => MediaStatus::Processing,
                'original_path' => $key,
                'sort_order' => ((int) $vehicle->media()->max('sort_order')) + ($count > 0 ? 1 : 0),
                'is_cover' => $count === 0,
            ]);
        });

        ProcessVehicleMedia::dispatch($media->id)->afterCommit();

        return $media;
    }
}
