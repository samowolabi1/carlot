<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\LotResource\Pages\ListLots;
use App\Filament\Resources\VehicleModelResource\Pages\ListVehicleModels;
use Livewire\Livewire;

it('only lets platform admins into /admin', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    $this->actingAs(User::factory()->staff()->create())->get('/admin')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
});

it('lets admins approve a pending lot', function () {
    $admin = User::factory()->admin()->create();
    $lot = Lot::factory()->create(['submitted_at' => now()]);

    $this->actingAs($admin);

    Livewire::test(ListLots::class)
        ->assertCanSeeTableRecords([$lot])
        ->callTableAction('approve', $lot);

    expect($lot->fresh()->status)->toBe(LotStatus::Active);
});

it('lets admins approve a model a seller typed in', function () {
    $admin = User::factory()->admin()->create();
    $make = Make::create(['name' => 'Toyota', 'slug' => 'toyota']);
    $model = VehicleModel::create(['make_id' => $make->id, 'name' => 'Crown Athlete', 'slug' => 'crown-athlete']);

    $this->actingAs($admin);
    $this->get('/admin/makes')->assertOk();

    Livewire::test(ListVehicleModels::class)
        ->assertCanSeeTableRecords([$model])
        ->callTableAction('approve', $model);

    expect($model->fresh()->approved_at)->not->toBeNull();
});
