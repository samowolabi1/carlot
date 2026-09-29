<?php

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;

it('gives buyers every photo of a live car for the quick look', function () {
    $car = Vehicle::factory()->available()->withPhoto()->create(['lot_id' => Lot::factory()->active()->create()->id]);

    $this->getJson(route('cars.photos', $car->ulid))
        ->assertOk()
        ->assertJsonPath('title', $car->title())
        ->assertJsonPath('url', $car->publicPath())
        ->assertJsonCount(1, 'photos')
        ->assertJsonPath('photos.0.full', fn (string $url) => str_ends_with($url, '-1600.webp'))
        ->assertJsonPath('photos.0.src', fn (string $url) => str_ends_with($url, '-800.webp'))
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('vin');
});

it('keeps drafts, held cars and unapproved lots out of the quick look', function () {
    $draft = Vehicle::factory()->withPhoto()->create(['lot_id' => Lot::factory()->active()->create()->id]);
    $held = Vehicle::factory()->available()->withPhoto()->create(['lot_id' => Lot::factory()->active()->create()->id, 'held_at' => now()]);
    $pending = Vehicle::factory()->available()->withPhoto()->create();

    foreach ([$draft, $held, $pending] as $car) {
        $this->getJson(route('cars.photos', $car->ulid))->assertNotFound();
    }
});
