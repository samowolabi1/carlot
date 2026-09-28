<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Events\VehiclePriceDropped;
use App\Domain\Inventory\Events\VehiclePublished;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Plan;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->vehicle = Vehicle::factory()->withPhoto()->create(['price' => null]);
    $this->lot = $this->vehicle->lot;
});

function priceStep(Vehicle $vehicle, array $data)
{
    return test()->put(route('dealer.vehicles.price', [$vehicle->lot, $vehicle]), $data);
}

it('saves the price in kobo and publishes', function () {
    Event::fake([VehiclePublished::class]);

    $this->actingAs($this->lot->owner);
    priceStep($this->vehicle, ['price' => '₦12,500,000', 'negotiable' => true, 'publish' => true])
        ->assertRedirect(route('dealer.vehicles.index', $this->lot));

    expect($this->vehicle->fresh())
        ->price->toBe(1_250_000_000)
        ->status->toBe(VehicleStatus::Available)
        ->listed_at->not->toBeNull();
    Event::assertDispatched(VehiclePublished::class);
});

it('will not publish without a photo', function () {
    $vehicle = Vehicle::factory()->create(['lot_id' => $this->lot->id]);

    $this->actingAs($this->lot->owner);
    priceStep($vehicle, ['price' => '5000000', 'publish' => true])->assertSessionHasErrors('publish');

    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Draft);
});

it('enforces the plan listing limit', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'free')->value('id')]); // 10 listings
    Vehicle::factory()->count(10)->available()->create(['lot_id' => $this->lot->id]);

    $this->actingAs($this->lot->owner);
    priceStep($this->vehicle, ['price' => '5000000', 'publish' => true])->assertSessionHasErrors('publish');

    expect($this->vehicle->fresh()->status)->toBe(VehicleStatus::Draft);
});

it('keeps price history and fires a price drop for listed cars', function () {
    Event::fake([VehiclePriceDropped::class]);
    $vehicle = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);

    $this->actingAs($this->lot->owner);
    priceStep($vehicle, ['price' => '9500000', 'negotiable' => true]);

    expect($vehicle->priceHistory()->sole())->old_price->toBe(1_000_000_000)->new_price->toBe(950_000_000)
        ->and($vehicle->fresh()->price_changed_at)->not->toBeNull();
    Event::assertDispatched(VehiclePriceDropped::class, fn ($e) => $e->oldPrice === 1_000_000_000 && $e->newPrice === 950_000_000);
});

it('does not record history while a car is still a draft', function () {
    Event::fake([VehiclePriceDropped::class]);
    $this->vehicle->update(['price' => 1_000_000_000]);

    $this->actingAs($this->lot->owner);
    priceStep($this->vehicle, ['price' => '9000000']);

    expect($this->vehicle->priceHistory()->count())->toBe(0);
    Event::assertNotDispatched(VehiclePriceDropped::class);
});

it('lets sales staff list cars but not change a live price', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => 'sales', 'accepted_at' => now()]);
    $live = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);

    $this->actingAs($sales);
    priceStep($this->vehicle, ['price' => '5000000', 'publish' => true])->assertRedirect();
    expect($this->vehicle->fresh()->status)->toBe(VehicleStatus::Available);

    priceStep($live, ['price' => '9000000'])->assertForbidden();
    expect($live->fresh()->price)->toBe(1_000_000_000);
    priceStep($live, ['price' => '10000000', 'negotiable' => false])->assertRedirect(); // same price, toggle only
});

it('hides, unhides and reserves cars through the state machine', function () {
    $vehicle = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->actingAs($this->lot->owner);
    $status = fn (string $to) => $this->patch(route('dealer.vehicles.status', [$this->lot, $vehicle]), ['status' => $to]);

    $status('hidden')->assertSessionHasNoErrors();
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Hidden);

    $listedAt = $vehicle->fresh()->listed_at;
    $status('available')->assertSessionHasNoErrors();
    expect($vehicle->fresh())->status->toBe(VehicleStatus::Available)
        ->and($vehicle->fresh()->listed_at->equalTo($listedAt))->toBeTrue();

    $status('reserved')->assertSessionHasNoErrors();
    $status('hidden')->assertSessionHasErrors('status');
    $status('sold')->assertSessionHasErrors('status');
    expect($vehicle->fresh()->status)->toBe(VehicleStatus::Reserved);
});

it('does not let sales staff change status or delete', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => 'sales', 'accepted_at' => now()]);
    $vehicle = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);

    $this->actingAs($sales)->patch(route('dealer.vehicles.status', [$this->lot, $vehicle]), ['status' => 'hidden'])->assertForbidden();
    $this->actingAs($sales)->delete(route('dealer.vehicles.destroy', [$this->lot, $vehicle]))->assertForbidden();
});

it('soft-deletes a car but never a sold one', function () {
    $this->actingAs($this->lot->owner)->delete(route('dealer.vehicles.destroy', [$this->lot, $this->vehicle]))->assertRedirect();
    expect(Vehicle::withoutGlobalScopes()->withTrashed()->find($this->vehicle->id)->trashed())->toBeTrue();

    $sold = Vehicle::factory()->create(['lot_id' => $this->lot->id]);
    $sold->forceFill(['status' => VehicleStatus::Sold])->save();
    $this->actingAs($this->lot->owner)->delete(route('dealer.vehicles.destroy', [$this->lot, $sold]))->assertStatus(422);
});
