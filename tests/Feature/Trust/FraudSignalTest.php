<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\ImageHash;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Jobs\DetectFraudSignals;
use App\Domain\Trust\Models\FraudSignal;
use App\Filament\Resources\FraudSignalResource\Pages\ListFraudSignals;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Livewire\Livewire;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    $this->prime = Lot::factory()->active()->create(['name' => 'Prime Motors', 'created_at' => now()->subYear()]);
    $this->ace = Lot::factory()->active()->create(['name' => 'Ace Autos', 'created_at' => now()->subYear()]);
    $this->detect = fn (Vehicle $v) => DetectFraudSignals::dispatchSync($v->id);
});

it('hashes photos so a re-saved copy matches and a different photo does not', function () {
    $manager = new ImageManager(new Driver);
    $photo = $manager->create(64, 48)->fill('#dddddd')->drawRectangle(8, 8, fn ($r) => $r->size(30, 20)->background('#222222'));
    $copy = $manager->read((string) (clone $photo)->scale(width: 128)->toJpeg(70));
    $other = $manager->create(64, 48)->fill('#dddddd')->drawRectangle(30, 20, fn ($r) => $r->size(28, 24)->background('#222222'));

    expect(ImageHash::distance(ImageHash::dhash($photo), ImageHash::dhash($copy)))->toBeLessThanOrEqual(ImageHash::MAX_DISTANCE)
        ->and(ImageHash::distance(ImageHash::dhash($photo), ImageHash::dhash($other)))->toBeGreaterThan(ImageHash::MAX_DISTANCE);
});

it('flags the same VIN or cover photo on another lot, without hiding anything', function () {
    $original = $this->car($this->prime, 'Toyota', 'Camry', ['vin' => '4T1B11HK8JU654821']);
    $original->cover->update(['phash' => 'f0e1d2c3b4a59687']);
    $copy = $this->car($this->ace, 'Toyota', 'Camry', ['vin' => '4T1B11HK8JU654821']);
    $copy->cover->update(['phash' => 'f0e1d2c3b4a59686']); // one bit apart

    ($this->detect)($copy);

    $signals = FraudSignal::where('vehicle_id', $copy->id)->get()->keyBy(fn ($s) => $s->type->value);
    expect($signals->keys()->sort()->values()->all())->toBe(['duplicate_photo', 'duplicate_vin'])
        ->and($signals['duplicate_vin']->label())->toBe('Same VIN as a Prime Motors listing')
        ->and($signals['duplicate_vin']->related_vehicle_id)->toBe($original->id)
        ->and($copy->fresh()->isOnMarketplace())->toBeTrue();

    // Running again (a price change) doesn't duplicate them.
    ($this->detect)($copy);
    expect(FraudSignal::count())->toBe(2);
});

it('flags a price far below the pricing guide', function () {
    foreach ([1_200_000_000, 1_250_000_000, 1_300_000_000, 1_280_000_000, 1_220_000_000] as $price) {
        $this->car($this->prime, 'Toyota', 'Camry', ['year' => 2018, 'price' => $price]);
    }
    $fair = $this->car($this->ace, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_100_000_000]);
    $cheap = $this->car($this->ace, 'Toyota', 'Camry', ['year' => 2018, 'price' => 450_000_000]);

    ($this->detect)($fair);
    ($this->detect)($cheap);

    expect(FraudSignal::where('vehicle_id', $fair->id)->exists())->toBeFalse()
        ->and(FraudSignal::where('vehicle_id', $cheap->id)->sole()->label())->toBe('Price 64% below guide');
});

it('flags a new lot publishing more than 30 cars in a day, and checks on every publish', function () {
    $new = Lot::factory()->active()->create(['name' => 'Quick Deals', 'created_at' => now()->subDays(2)]);
    Vehicle::factory()->count(31)->withPhoto()->available()->create(['lot_id' => $new->id, 'listed_at' => now()->subHours(2)]);
    $draft = Vehicle::factory()->withPhoto()->create(['lot_id' => $new->id, 'price' => 500_000_000, 'mileage_km' => 1000, 'condition' => 'foreign_used', 'transmission' => 'automatic', 'fuel' => 'petrol']);
    $draft->forceFill(['make_id' => $this->car($this->prime, 'Honda', 'Accord')->make_id, 'vehicle_model_id' => Vehicle::latest('id')->first()->vehicle_model_id, 'year' => 2016])->save();
    $new->forceFill(['plan_id' => null])->save();

    app(PublishVehicle::class)->run($draft->fresh());

    expect(FraudSignal::where('vehicle_id', $draft->id)->sole()->label())->toBe('32 cars in 24 h');
});

it('lets admins clear or act on flagged listings', function () {
    $original = $this->car($this->prime, 'Toyota', 'Camry', ['vin' => '4T1B11HK8JU654821']);
    $copy = $this->car($this->ace, 'Toyota', 'Camry', ['vin' => '4T1B11HK8JU654821']);
    ($this->detect)($copy);
    $signal = FraudSignal::sole();

    $this->actingAs(User::factory()->admin()->create());
    $this->get('/admin/fraud-signals')->assertOk();
    Livewire::test(ListFraudSignals::class)
        ->assertCanSeeTableRecords([$signal])
        ->callTableAction('message', $signal, ['note' => 'Please confirm which seller currently holds this car.'])
        ->callTableAction('hide', $signal, ['reason' => 'Same VIN as another lot']);

    expect($copy->fresh()->isHeld())->toBeTrue()->and($signal->fresh()->status->value)->toBe('actioned')
        ->and($this->ace->owner->notifications()->count())->toBe(2)
        ->and($this->prime->owner->notifications()->count())->toBe(1)
        ->and($original->fresh()->isHeld())->toBeFalse();
});
