<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Actions\ArrangeVehicleMedia;
use App\Domain\Inventory\Actions\AttachVehicleMedia;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Inventory\Support\MediaUploads;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Photo uploads are JSON endpoints driven by the uploader component:
 * presign → PUT to R2 (or POST to upload() locally) → store() → processing job.
 */
class VehicleMediaController extends Controller
{
    public function presign(Request $request, Lot $lot, Vehicle $vehicle, MediaUploads $uploads): JsonResponse
    {
        Gate::authorize('update', $vehicle);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(MediaUploads::MIME_TYPES))],
            'size' => ['required', 'integer', 'max:'.MediaUploads::MAX_BYTES],
        ], ['size.max' => 'Photos can be up to 12 MB.', 'type.in' => 'Use JPEG, PNG or WebP photos.']);

        abort_if($vehicle->media()->count() >= Vehicle::MAX_PHOTOS, 422, 'A car can have up to '.Vehicle::MAX_PHOTOS.' photos.');

        $key = $uploads->newKey($vehicle, $data['type']);

        if ($uploads->isDirect()) {
            return response()->json(['key' => $key, ...$uploads->presign($key, $data['type'])]);
        }

        return response()->json([
            'key' => $key,
            'method' => 'POST',
            'url' => route('dealer.vehicles.media.upload', [$lot, $vehicle]),
            'headers' => [],
        ]);
    }

    /** Local-disk fallback for presign(): the browser posts the file here. */
    public function upload(Request $request, Lot $lot, Vehicle $vehicle, MediaUploads $uploads): JsonResponse
    {
        Gate::authorize('update', $vehicle);
        abort_if($uploads->isDirect(), 404);

        $request->validate([
            'key' => ['required', 'string'],
            'file' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(MediaUploads::MIME_TYPES)), 'max:'.(MediaUploads::MAX_BYTES / 1024)],
        ], ['file.max' => 'Photos can be up to 12 MB.', 'file.mimetypes' => 'Use JPEG, PNG or WebP photos.']);

        $key = $request->string('key')->toString();
        abort_unless($uploads->keyBelongsTo($key, $vehicle), 422, 'Invalid upload key.');

        $uploads->disk()->putFileAs(dirname($key), $request->file('file'), basename($key));

        return response()->json(['key' => $key]);
    }

    public function store(Request $request, Lot $lot, Vehicle $vehicle, AttachVehicleMedia $attach): JsonResponse
    {
        Gate::authorize('update', $vehicle);

        $data = $request->validate(['key' => ['required', 'string']]);
        $media = $attach->run($vehicle, $data['key']);

        return response()->json(VehicleResource::media($media->refresh()), 201);
    }

    public function reorder(Request $request, Lot $lot, Vehicle $vehicle, ArrangeVehicleMedia $arrange): JsonResponse
    {
        Gate::authorize('update', $vehicle);

        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string']]);
        $arrange->reorder($vehicle, array_values($data['order']));

        return response()->json(['ok' => true]);
    }

    public function destroy(Lot $lot, Vehicle $vehicle, VehicleMedia $media, ArrangeVehicleMedia $arrange): JsonResponse
    {
        Gate::authorize('update', $vehicle);

        $arrange->delete($vehicle, $media);

        return response()->json(['ok' => true]);
    }

    /** Polled by the uploader while photos are processing. */
    public function index(Lot $lot, Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('update', $vehicle);

        return response()->json($vehicle->media()->get()->map(fn (VehicleMedia $m) => VehicleResource::media($m))->values());
    }
}
