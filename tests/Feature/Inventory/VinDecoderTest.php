<?php

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Inventory\Support\NhtsaVinDecoder;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Http;

const CAMRY_VIN = '4T1B11HK8JU654821';

function fakeNhtsa(array $row): void
{
    Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response(['Results' => [$row]])]);
}

$camry = [
    'Make' => 'TOYOTA', 'Model' => 'Camry', 'ModelYear' => '2018', 'Trim' => 'SE',
    'DisplacementCC' => '2487.0', 'FuelTypePrimary' => 'Gasoline', 'DriveType' => 'FWD/Front-Wheel Drive',
    'BodyClass' => 'Sedan/Saloon',
];

it('maps NHTSA fields', function () use ($camry) {
    fakeNhtsa($camry);

    $decoded = (new NhtsaVinDecoder)->decode(CAMRY_VIN);

    expect($decoded)
        ->make->toBe('Toyota')
        ->model->toBe('Camry')
        ->year->toBe(2018)
        ->trim->toBe('SE')
        ->engineCc->toBe(2487)
        ->fuel->toBe(FuelType::Petrol)
        ->drivetrain->toBe(Drivetrain::Fwd)
        ->bodyType->toBe(BodyType::Sedan);
});

it('caches results per VIN', function () use ($camry) {
    fakeNhtsa($camry);

    (new NhtsaVinDecoder)->decode(CAMRY_VIN);
    (new NhtsaVinDecoder)->decode(strtolower(CAMRY_VIN));

    Http::assertSentCount(1);
});

it('returns null for unknown VINs', function () {
    fakeNhtsa(['Make' => '', 'Model' => '', 'ModelYear' => '', 'ErrorCode' => '8']);

    expect((new NhtsaVinDecoder)->decode(CAMRY_VIN))->toBeNull();
});

it('matches the decoded make and model in the catalogue', function () use ($camry) {
    $lot = Lot::factory()->create();
    $lexus = Make::create(['name' => 'Lexus', 'slug' => 'lexus']);
    $rx = VehicleModel::create(['make_id' => $lexus->id, 'name' => 'RX 350', 'slug' => 'rx-350', 'approved_at' => now()]);
    fakeNhtsa([...$camry, 'Make' => 'LEXUS', 'Model' => 'RX', 'BodyClass' => 'Sport Utility Vehicle (SUV)/Multi-Purpose Vehicle (MPV)', 'DriveType' => 'AWD/All-Wheel Drive']);

    $this->actingAs($lot->owner)
        ->postJson(route('dealer.vehicles.decode-vin', $lot), ['vin' => CAMRY_VIN])
        ->assertOk()
        ->assertJson([
            'found' => true,
            'make_id' => $lexus->id,
            'vehicle_model_id' => $rx->id,
            'decoded' => ['body_type' => 'suv', 'drivetrain' => 'awd', 'year' => 2018],
        ]);
});

it('says so when the decoder is down', function () {
    $lot = Lot::factory()->create();
    Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response('', 500)]);

    $this->actingAs($lot->owner)
        ->postJson(route('dealer.vehicles.decode-vin', $lot), ['vin' => CAMRY_VIN])
        ->assertStatus(503)
        ->assertJson(['found' => false]);
});

it('rejects malformed VINs', function () {
    $lot = Lot::factory()->create();

    $this->actingAs($lot->owner)
        ->postJson(route('dealer.vehicles.decode-vin', $lot), ['vin' => '4T1B11HK8JU65482O'])
        ->assertJsonValidationErrors('vin');
});
