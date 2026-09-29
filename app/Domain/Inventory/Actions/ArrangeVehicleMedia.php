<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Sharing\Jobs\RenderShareCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ArrangeVehicleMedia
{
    /**
     * Saves the gallery order; the first photo is the cover.
     *
     * @param  list<string>  $ulids  every media ULID of the vehicle, in the new order
     */
    public function reorder(Vehicle $vehicle, array $ulids): void
    {
        $media = $vehicle->media()->get()->keyBy('ulid');

        if (count($ulids) !== $media->count() || array_diff($ulids, $media->keys()->all()) !== []) {
            throw ValidationException::withMessages(['order' => 'The photo list changed. Refresh and try again.']);
        }

        DB::transaction(function () use ($ulids, $media): void {
            foreach ($ulids as $position => $ulid) {
                $media[$ulid]->update(['sort_order' => $position, 'is_cover' => $position === 0]);
            }
        });

        RenderShareCard::refresh($vehicle->id);
    }

    /** "Make cover": moves the photo to the front, keeping the others in order. */
    public function makeCover(Vehicle $vehicle, VehicleMedia $media): void
    {
        $ulids = $vehicle->media()->pluck('ulid')->reject(fn (string $u) => $u === $media->ulid)->prepend($media->ulid)->values()->all();
        $this->reorder($vehicle, $ulids);
    }

    public function delete(Vehicle $vehicle, VehicleMedia $media): void
    {
        $files = array_values($media->variantPaths());

        DB::transaction(function () use ($vehicle, $media): void {
            $media->delete();

            $ulids = $vehicle->media()->pluck('ulid')->all();

            foreach ($ulids as $position => $ulid) {
                $vehicle->media()->where('ulid', $ulid)->update(['sort_order' => $position, 'is_cover' => $position === 0]);
            }
        });

        Storage::disk(config('lotlink.media_disk'))->delete($files);
        RenderShareCard::refresh($vehicle->id);

        if ($media->original_path) {
            Storage::disk(config('lotlink.upload_disk'))->delete($media->original_path);
        }
    }
}
