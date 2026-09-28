<?php

use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->vehicle = Vehicle::factory()->create();
    $this->lot = $this->vehicle->lot;
    $this->actingAs($this->lot->owner);
});

function uploadPhoto(Vehicle $vehicle, ?UploadedFile $file = null): array
{
    $file ??= UploadedFile::fake()->image('front.jpg', 2000, 1500);

    $target = test()->postJson(route('dealer.vehicles.media.presign', [$vehicle->lot, $vehicle]), ['type' => 'image/jpeg', 'size' => $file->getSize()])
        ->assertOk()->json();

    test()->post($target['url'], ['key' => $target['key'], 'file' => $file], ['Accept' => 'application/json'])->assertOk();

    return test()->postJson(route('dealer.vehicles.media.store', [$vehicle->lot, $vehicle]), ['key' => $target['key']])
        ->assertCreated()->json();
}

it('uses the app as the upload target on a local disk', function () {
    $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/jpeg', 'size' => 1000])
        ->assertOk()
        ->assertJson(['method' => 'POST', 'url' => route('dealer.vehicles.media.upload', [$this->lot, $this->vehicle])])
        ->assertJsonPath('key', fn (string $key) => str_starts_with($key, "uploads/{$this->vehicle->ulid}/"));
});

it('turns an upload into WebP renditions and removes the original', function () {
    $media = uploadPhoto($this->vehicle);

    $record = VehicleMedia::where('ulid', $media['ulid'])->sole();
    expect($record->status)->toBe(MediaStatus::Ready)
        ->and($record->is_cover)->toBeTrue()
        ->and($record->width)->toBe(1600)
        ->and($record->height)->toBe(1200)
        ->and($record->original_path)->toBeNull();

    foreach ([1600, 800, 400] as $width) {
        $path = VehicleMedia::variantPath($this->vehicle->ulid, $record->ulid, $width);
        Storage::disk('public')->assertExists($path);
        expect(getimagesizefromstring(Storage::disk('public')->get($path))['mime'])->toBe('image/webp');
    }

    expect(Storage::disk('local')->allFiles('uploads'))->toBeEmpty();
});

it('does not upscale small photos', function () {
    $media = uploadPhoto($this->vehicle, UploadedFile::fake()->image('small.jpg', 640, 480));

    expect(VehicleMedia::where('ulid', $media['ulid'])->sole()->width)->toBe(640);
});

it('rejects files that are not photos', function () {
    $target = $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/jpeg', 'size' => 100])->json();

    $this->post($target['url'], ['key' => $target['key'], 'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('rejects photos over 12 MB before upload', function () {
    $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/jpeg', 'size' => 13 * 1024 * 1024])
        ->assertJsonValidationErrors('size');
});

it('will not attach an upload key issued for another car', function () {
    $other = Vehicle::factory()->create(['lot_id' => $this->lot->id]);
    $target = $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $other]), ['type' => 'image/jpeg', 'size' => 100])->json();
    $this->post($target['url'], ['key' => $target['key'], 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);

    $this->postJson(route('dealer.vehicles.media.store', [$this->lot, $this->vehicle]), ['key' => $target['key']])
        ->assertJsonValidationErrors('key');
});

it('caps a car at 20 photos', function () {
    foreach (range(1, 20) as $i) {
        $this->vehicle->media()->create(['status' => MediaStatus::Ready, 'sort_order' => $i]);
    }

    $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/jpeg', 'size' => 100])
        ->assertUnprocessable();
});

it('reorders photos and moves the cover with the first one', function () {
    $a = uploadPhoto($this->vehicle);
    $b = uploadPhoto($this->vehicle);

    $this->putJson(route('dealer.vehicles.media.reorder', [$this->lot, $this->vehicle]), ['order' => [$b['ulid'], $a['ulid']]])->assertOk();

    expect(VehicleMedia::where('ulid', $b['ulid'])->sole())->is_cover->toBeTrue()->sort_order->toBe(0)
        ->and(VehicleMedia::where('ulid', $a['ulid'])->sole())->is_cover->toBeFalse();
});

it('deletes a photo and its files, promoting the next one to cover', function () {
    $a = uploadPhoto($this->vehicle);
    $b = uploadPhoto($this->vehicle);

    $this->deleteJson(route('dealer.vehicles.media.destroy', [$this->lot, $this->vehicle, $a['ulid']]))->assertOk();

    Storage::disk('public')->assertMissing(VehicleMedia::variantPath($this->vehicle->ulid, $a['ulid'], 1600));
    expect(VehicleMedia::where('ulid', $b['ulid'])->sole()->is_cover)->toBeTrue();
});

it('marks unreadable uploads as failed', function () {
    $target = $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/jpeg', 'size' => 100])->json();
    Storage::disk('local')->put($target['key'], 'not really a jpeg');

    $media = $this->postJson(route('dealer.vehicles.media.store', [$this->lot, $this->vehicle]), ['key' => $target['key']])->json();

    expect(VehicleMedia::where('ulid', $media['ulid'])->sole()->status)->toBe(MediaStatus::Failed);
});

it('hands out a pre-signed PUT URL when uploads go to R2', function () {
    config([
        'filesystems.disks.r2' => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'lotlink-uploads',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
        ],
        'lotlink.upload_disk' => 'r2',
    ]);

    $response = $this->postJson(route('dealer.vehicles.media.presign', [$this->lot, $this->vehicle]), ['type' => 'image/webp', 'size' => 5000])
        ->assertOk()
        ->assertJson(['method' => 'PUT', 'headers' => ['Content-Type' => 'image/webp']]);

    expect($response->json('url'))
        ->toStartWith('https://account.r2.cloudflarestorage.com/lotlink-uploads/uploads/'.$this->vehicle->ulid.'/')
        ->toContain('X-Amz-Signature=')
        ->and($response->json('key'))->toEndWith('.webp');

    // The local upload endpoint is closed when uploads go direct.
    $this->post(route('dealer.vehicles.media.upload', [$this->lot, $this->vehicle]), [], ['Accept' => 'application/json'])->assertNotFound();
});
