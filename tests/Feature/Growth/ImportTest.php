<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleImport;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    Storage::fake('local');
    Make::create(['name' => 'Toyota', 'slug' => 'toyota']);
    Make::create(['name' => 'Honda', 'slug' => 'honda']);
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->forceFill(['status' => 'active', 'plan_id' => Plan::where('code', 'enterprise')->value('id')])->save();

    $this->csv = fn (array $rows) => UploadedFile::fake()->createWithContent('stock.csv', implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r)), [
        ['vin', 'make', 'model', 'year', 'trim', 'body_type', 'mileage_km', 'condition', 'transmission', 'fuel', 'engine_cc', 'colour', 'duty', 'price', 'negotiable', 'description'],
        ...$rows,
    ])));
});

it('imports good rows as drafts and lists the problems with the others', function () {
    $this->actingAs($this->owner)->get(route('dealer.vehicles.import.template', $this->lot))->assertOk()->assertDownload('lotlink-stock-template.xlsx');

    $this->actingAs($this->owner)->post(route('dealer.vehicles.import.store', $this->lot), ['file' => ($this->csv)([
        ['4T1B11HK8JU654821', 'Toyota', 'Camry', '2018', 'SE', 'Sedan', '62,400', 'Tokunbo', 'Automatic', 'Petrol', '2.5', 'Silver', 'Paid', '₦12,500,000', 'Yes', 'Clean'],
        ['', 'Honda', 'Accord', '2016', '', 'saloon', '91000', 'Nigerian used', 'auto', 'petrol', '2400', 'Black', 'paid', '7900000', 'No', ''],
        ['', 'Bugatti', 'Veyron', '2011', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['', 'Toyota', 'Corolla', '1950', '', 'spaceship', '', '', '', '', '', '', '', '12', '', ''],
    ])])->assertSessionHas('success');

    $import = VehicleImport::withoutGlobalScopes()->sole();
    expect($import)->status->toBe('done')->total_rows->toBe(4)->imported_rows->toBe(2)
        ->and(collect($import->errors)->pluck('row')->all())->toBe([4, 5])
        ->and($import->errors[0]['messages'][0])->toBe('We don\'t know the make "Bugatti".')
        ->and($import->errors[1]['messages'])->toContain('Year should be between 1980 and 2027.');

    $camry = Vehicle::withoutGlobalScopes()->where('vin', '4T1B11HK8JU654821')->sole();
    expect($camry)->status->value->toBe('draft')->price->toBe(1_250_000_000)->mileage_km->toBe(62400)->engine_cc->toBe(2500)
        ->condition->value->toBe('foreign_used')->body_type->value->toBe('sedan')->negotiable->toBeTrue();
    $accord = Vehicle::withoutGlobalScopes()->whereNull('vin')->sole();
    expect($accord)->condition->value->toBe('locally_used')->transmission->value->toBe('automatic')->negotiable->toBeFalse();

    expect($this->owner->notifications()->where('data->kind', 'import')->sole()->data['text'])->toContain('2 cars added as drafts, 2 rows to fix');

    // Uploading the same file again skips the VIN already in stock.
    $this->actingAs($this->owner)->post(route('dealer.vehicles.import.store', $this->lot), ['file' => ($this->csv)([
        ['4T1B11HK8JU654821', 'Toyota', 'Camry', '2018', '', '', '', '', '', '', '', '', '', '', '', ''],
    ])]);
    expect(VehicleImport::withoutGlobalScopes()->latest('id')->first()->errors[0]['messages'])->toBe(['A car with this VIN is already in your stock.']);

    $this->actingAs($this->owner)->get(route('dealer.vehicles.import', $this->lot))->assertInertia(fn (Assert $page) => $page->where('allowed', true)->has('imports', 2));
});

it('is an Enterprise feature for owners and managers, kept to the lot', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->get(route('dealer.vehicles.import', $this->lot))->assertForbidden();

    $other = User::factory()->staff()->create();
    app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($other)->post(route('dealer.vehicles.import.store', $this->lot), ['file' => ($this->csv)([])])->assertForbidden();

    $this->lot->forceFill(['plan_id' => Plan::where('code', 'pro')->value('id')])->save();
    $this->actingAs($this->owner)->get(route('dealer.vehicles.import', $this->lot))->assertInertia(fn (Assert $page) => $page->where('allowed', false));
    $this->actingAs($this->owner)->post(route('dealer.vehicles.import.store', $this->lot), ['file' => ($this->csv)([])])->assertForbidden();
    expect(VehicleImport::withoutGlobalScopes()->count())->toBe(0);
});
