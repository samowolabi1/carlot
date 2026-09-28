<?php

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;

it('hides another lot\'s cars behind 404s', function (string $method, string $routeName, array $extra) {
    $mine = Lot::factory()->create();
    $theirs = Vehicle::factory()->withPhoto()->create();

    $this->actingAs($mine->owner)
        ->{$method}(route($routeName, [$mine, $theirs, ...$extra]), [])
        ->assertNotFound();
})->with([
    ['get', 'dealer.vehicles.edit', ['details']],
    ['put', 'dealer.vehicles.details', []],
    ['put', 'dealer.vehicles.price', []],
    ['patch', 'dealer.vehicles.status', []],
    ['delete', 'dealer.vehicles.destroy', []],
    ['postJson', 'dealer.vehicles.media.presign', []],
    ['getJson', 'dealer.vehicles.media.index', []],
]);

it('forbids another lot\'s stock pages outright', function () {
    $mine = Lot::factory()->create();
    $theirs = Lot::factory()->create();

    $this->actingAs($mine->owner)->get(route('dealer.vehicles.index', $theirs))->assertForbidden();
    $this->actingAs($mine->owner)->get(route('dealer.vehicles.create', $theirs))->assertForbidden();
});

it('does not delete media belonging to another car', function () {
    $vehicle = Vehicle::factory()->withPhoto()->create();
    $other = Vehicle::factory()->withPhoto()->create(['lot_id' => $vehicle->lot_id]);

    $this->actingAs($vehicle->lot->owner)
        ->deleteJson(route('dealer.vehicles.media.destroy', [$vehicle->lot, $vehicle, $other->media()->first()->ulid]))
        ->assertNotFound();
});
