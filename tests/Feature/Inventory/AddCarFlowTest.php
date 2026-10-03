<?php

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Feature;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Models\Lot;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->lot = Lot::factory()->create();
    $this->toyota = Make::create(['name' => 'Toyota', 'slug' => 'toyota']);
    $this->camry = VehicleModel::create(['make_id' => $this->toyota->id, 'name' => 'Camry', 'slug' => 'camry', 'body_type' => 'sedan', 'approved_at' => now()]);
});

function identity(array $overrides = []): array
{
    return [
        'vin' => '4T1B11HK8JU654821',
        'make_id' => test()->toyota->id,
        'vehicle_model_id' => test()->camry->id,
        'year' => 2018,
        'trim' => 'SE',
        'decoded' => ['engine_cc' => 2487, 'fuel' => 'petrol', 'drivetrain' => 'fwd', 'body_type' => 'sedan'],
        ...$overrides,
    ];
}

it('renders the first step with the catalogue', function () {
    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.create', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->component('Dealer/Vehicles/Wizard')->where('step', 'identity')->has('makes', 1));
});

it('creates a draft from the VIN step and prefills decoded details', function () {
    $this->actingAs($this->lot->owner)->post(route('dealer.vehicles.store', $this->lot), identity(['vin' => '4t1b11hk8ju654821']))
        ->assertRedirect();

    $vehicle = Vehicle::withoutGlobalScopes()->sole();
    expect($vehicle)
        ->status->toBe(VehicleStatus::Draft)
        ->lot_id->toBe($this->lot->id)
        ->vin->toBe('4T1B11HK8JU654821')
        ->slug->toBe('2018-toyota-camry-se')
        ->engine_cc->toBe(2487)
        ->created_by->toBe($this->lot->owner->id)
        ->and($vehicle->body_type->value)->toBe('sedan');
});

it('rejects a VIN already in the seller\'s stock', function () {
    Vehicle::factory()->create(['lot_id' => $this->lot->id, 'vin' => '4T1B11HK8JU654821', 'vehicle_model_id' => $this->camry->id]);

    $this->actingAs($this->lot->owner)->post(route('dealer.vehicles.store', $this->lot), identity())
        ->assertSessionHasErrors('vin');
});

it('allows the same VIN at another lot', function () {
    Vehicle::factory()->create(['vin' => '4T1B11HK8JU654821', 'vehicle_model_id' => $this->camry->id]);

    $this->actingAs($this->lot->owner)->post(route('dealer.vehicles.store', $this->lot), identity())->assertSessionHasNoErrors();
});

it('adds a typed model for admin review', function () {
    $this->actingAs($this->lot->owner)->post(route('dealer.vehicles.store', $this->lot), identity([
        'vin' => null, 'vehicle_model_id' => null, 'model_name' => 'Crown  Athlete',
    ]))->assertSessionHasNoErrors();

    $model = VehicleModel::where('slug', 'crown-athlete')->sole();
    expect($model->name)->toBe('Crown Athlete')
        ->and($model->approved_at)->toBeNull()
        ->and(Vehicle::withoutGlobalScopes()->sole()->vehicle_model_id)->toBe($model->id);
});

it('rejects a model from another make', function () {
    $honda = Make::create(['name' => 'Honda', 'slug' => 'honda']);
    $accord = VehicleModel::create(['make_id' => $honda->id, 'name' => 'Accord', 'slug' => 'accord', 'approved_at' => now()]);

    $this->actingAs($this->lot->owner)->post(route('dealer.vehicles.store', $this->lot), identity(['vehicle_model_id' => $accord->id]))
        ->assertSessionHasErrors('vehicle_model_id');
});

it('saves details and features, then moves to photos', function () {
    $vehicle = Vehicle::factory()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->camry->id]);
    $camera = Feature::create(['name' => 'Reverse camera', 'slug' => 'reverse-camera', 'group' => 'safety']);

    $this->actingAs($this->lot->owner)->put(route('dealer.vehicles.details', [$this->lot, $vehicle]), [
        'mileage_km' => 48200,
        'condition' => 'foreign_used',
        'transmission' => 'automatic',
        'fuel' => 'petrol',
        'colour' => 'Silver',
        'duty_status' => 'paid',
        'registered' => true,
        'feature_ids' => [$camera->id],
        'wizard' => true,
    ])->assertRedirect(route('dealer.vehicles.edit', [$this->lot, $vehicle, 'photos']));

    expect($vehicle->fresh())->mileage_km->toBe(48200)->colour->toBe('Silver')->registered->toBeTrue()
        ->and($vehicle->features()->pluck('features.id')->all())->toBe([$camera->id]);
});

it('renders each wizard step for a draft', function (string $step) {
    $vehicle = Vehicle::factory()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->camry->id]);

    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.edit', [$this->lot, $vehicle, $step]))
        ->assertInertia(fn (Assert $page) => $page->component('Dealer/Vehicles/Wizard')->where('vehicle.ulid', $vehicle->ulid));
})->with(['identity', 'details', 'photos', 'price']);

it('lists stock with status counts', function () {
    Vehicle::factory()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->camry->id]);
    Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->camry->id]);
    Vehicle::factory()->available()->create(['vehicle_model_id' => $this->camry->id]); // another lot

    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Vehicles/Index')
            ->has('vehicles.data', 2)
            ->where('counts.all', 2)
            ->where('counts.available', 1)
            ->where('counts.draft', 1));
});

it('searches stock by model name and VIN', function () {
    Vehicle::factory()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->camry->id, 'vin' => '4T1B11HK8JU654821']);

    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.index', [$this->lot, 'q' => 'camry']))
        ->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 1));
    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.index', [$this->lot, 'q' => '654821']))
        ->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 1));
    $this->actingAs($this->lot->owner)->get(route('dealer.vehicles.index', [$this->lot, 'q' => 'accord']))
        ->assertInertia(fn (Assert $page) => $page->has('vehicles.data', 0));
});
