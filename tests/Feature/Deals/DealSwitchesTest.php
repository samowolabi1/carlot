<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\LotMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

/* Owners choose whether buyers can send trade-ins and car loan applications (Settings → Offers and deals). */

beforeEach(function () {
    Storage::fake('local');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_000_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777']);
    $make = Make::firstOrCreate(['slug' => 'honda'], ['name' => 'Honda']);
    $model = VehicleModel::firstOrCreate(['make_id' => $make->id, 'slug' => 'civic'], ['name' => 'Civic', 'approved_at' => now()]);

    $this->settings = fn (array $data, ?User $as = null) => $this->actingAs($as ?? $this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => true, 'reservation_deposit' => '', 'reservation_refundable' => false, ...$data,
    ]);
    $this->tradeIn = fn () => $this->actingAs($this->buyer)->post(route('trade-ins.store', $this->lot->slug), [
        'make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2014, 'mileage_km' => '118,000', 'condition' => 'good',
        'photos' => [UploadedFile::fake()->image('front.jpg', 1200, 900)],
    ]);
    $this->apply = fn () => $this->actingAs($this->buyer)->post(route('finance.store', $this->car->ulid), [
        'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ]);
    $this->carPage = fn () => $this->actingAs($this->buyer)->get($this->car->publicPath());
});

it('takes trade-ins and loan applications until the owner turns them off', function () {
    expect($this->lot->fresh())->accepts_trade_ins->toBeTrue()->accepts_finance->toBeTrue();

    ($this->carPage)()->assertInertia(fn (Assert $page) => $page->where('deals.trade_ins', true)->where('deals.finance', true));
    $this->actingAs($this->owner)->get(route('dealer.settings', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('deals.accepts_trade_ins', true)->where('deals.accepts_finance', true));
});

it('hides and refuses trade-ins when the owner turns them off', function () {
    ($this->settings)(['accepts_trade_ins' => false])->assertSessionHasNoErrors();

    ($this->carPage)()->assertInertia(fn (Assert $page) => $page->where('deals.trade_ins', false)->where('deals.finance', true));
    $this->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('lot.trade_ins', false));
    $this->actingAs($this->buyer)->get(route('trade-ins.create', $this->lot->slug))
        ->assertRedirect(route('lots.show', $this->lot))->assertSessionHas('error', "Prime Motors isn't taking trade-ins right now.");
    ($this->tradeIn)()->assertSessionHasErrors(['make_id' => "Prime Motors isn't taking trade-ins right now."]);
    expect(TradeIn::withoutGlobalScopes()->count())->toBe(0);

    ($this->settings)(['accepts_trade_ins' => true]);
    ($this->tradeIn)()->assertSessionHasNoErrors();
    expect(TradeIn::withoutGlobalScopes()->count())->toBe(1);
});

it('hides and refuses loan applications when the owner turns them off', function () {
    ($this->settings)(['accepts_finance' => false])->assertSessionHasNoErrors();

    ($this->carPage)()->assertInertia(fn (Assert $page) => $page->where('deals.finance', false)->where('deals.trade_ins', true)
        ->has('finance')); // the monthly estimate still shows
    $this->actingAs($this->buyer)->get(route('finance.create', $this->car->ulid))
        ->assertRedirect($this->car->publicPath())->assertSessionHas('error', "Prime Motors isn't taking car loan applications right now.");
    ($this->apply)()->assertSessionHasErrors('monthly_income');
    expect(FinanceApplication::count())->toBe(0);

    ($this->settings)(['accepts_finance' => true]);
    ($this->apply)()->assertSessionHasNoErrors();
    expect(FinanceApplication::count())->toBe(1);
});

it('lets owners and managers change the switches, not sales staff', function () {
    $sales = User::factory()->staff()->create(['phone' => '+2348020000002']);
    LotMember::create(['lot_id' => $this->lot->id, 'user_id' => $sales->id, 'role' => LotRole::Sales]);

    ($this->settings)(['accepts_trade_ins' => false], $sales)->assertForbidden();
    expect($this->lot->fresh()->accepts_trade_ins)->toBeTrue();
});
