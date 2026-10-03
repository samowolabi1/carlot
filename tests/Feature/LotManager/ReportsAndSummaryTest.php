<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id')]);
    $this->sales = User::factory()->staff()->create(['name' => 'Tunde Bello']);
    $this->lot->members()->attach($this->sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);

    // Two walk-ins (one from Instagram who buys), one sale with costs, one open balance.
    $this->actingAs($this->sales)->post(route('dealer.manager.walk-ins.store', $this->lot), ['name' => 'Ada Obi', 'phone' => '08035550101', 'source' => 'instagram']);
    $this->actingAs($this->sales)->post(route('dealer.manager.walk-ins.store', $this->lot), ['name' => 'Bola Ade', 'phone' => '08035550102', 'source' => 'walk_in']);

    $sold = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);
    $open = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 500_000_000]);
    $this->actingAs($this->owner)->post(route('dealer.vehicles.costs.store', [$this->lot, $sold]), ['type' => 'purchase', 'amount' => '8,000,000', 'incurred_at' => '2026-09-01']);

    $this->actingAs($this->sales)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $sold->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101']);
    $order = SalesOrder::withoutGlobalScopes()->latest('id')->first();
    $this->actingAs($this->sales)->post(route('dealer.manager.orders.payments.store', [$this->lot, $order]), ['amount' => '10,000,000', 'method' => 'transfer']);
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered']);

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $open->ulid, 'name' => 'Chidi Eze', 'phone' => '08035550103']);
    $second = SalesOrder::withoutGlobalScopes()->latest('id')->first();
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.payments.store', [$this->lot, $second]), ['amount' => '1,000,000', 'method' => 'cash']);
});

it('reports sales and profit by month', function () {
    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'sales', 'period' => 'year']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Manager/Reports')
            ->where('report.rows.0.month', 'Oct 2026')
            ->where('report.rows.0.cars', 1)
            ->where('report.rows.0.revenue', 10_000_000)
            ->where('report.rows.0.received', 11_000_000)
            ->where('report.rows.0.profit', 2_000_000)
            ->where('report.totals.cars', 1));
});

it('reports balances, walk-in conversion and staff', function () {
    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'balances']))
        ->assertInertia(fn (Assert $page) => $page->has('report.rows', 1)->where('report.rows.0.customer', 'Chidi Eze')->where('report.rows.0.balance', 4_000_000));

    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'walk-ins', 'period' => '30d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.rows', fn ($rows) => collect($rows)->firstWhere('source', 'Instagram')['conversion'] === 100
                && collect($rows)->firstWhere('source', 'Walked in')['conversion'] === 0)
            ->where('report.totals.conversion', 50));

    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'staff', 'period' => '30d']))
        ->assertInertia(fn (Assert $page) => $page->where('report.rows.0.name', 'Tunde Bello')->where('report.rows.0.walk_ins', 2)->where('report.rows.0.sold', 1)->where('report.rows.0.sales', 10_000_000));
});

it('exports to Excel on Pro', function () {
    $response = $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'sales', 'export' => 'xlsx']));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('caryard-prime-motors-sales-2026-10-05.xlsx');
});

it('keeps reports to owners and managers, and the Pro parts to Pro', function () {
    $this->actingAs($this->sales)->get(route('dealer.manager.reports', $this->lot))->assertForbidden();

    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'sales']))
        ->assertInertia(fn (Assert $page) => $page->where('pro', false)->where('report.rows.0', fn ($row) => ! isset($row['profit'])));
    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'type' => 'staff']))->assertForbidden();
    $this->actingAs($this->owner)->get(route('dealer.manager.reports', [$this->lot, 'export' => 'xlsx']))->assertForbidden();
});

it('sends the owner a daily summary at 19:00 lot time, once', function () {
    $this->artisan('manager:daily-summary');
    expect($this->whatsapp->to('+2348020000001', 'daily_summary'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:05', 'UTC')); // 19:05 Lagos
    $this->artisan('manager:daily-summary');
    $this->artisan('manager:daily-summary');

    $sent = $this->whatsapp->to('+2348020000001', 'daily_summary');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params)->toBe(['Prime Motors', 'Mon 5 Oct', '2', '2', '₦11,000,000', '₦4,000,000', '0'])
        ->and($sent[0]->text)->toContain('Bank transfer ₦10m, Cash ₦1m');

    // The next evening it goes again.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 18:05', 'UTC'));
    $this->artisan('manager:daily-summary');
    expect($this->whatsapp->to('+2348020000001', 'daily_summary'))->toHaveCount(2);
});

it('does not send the summary on the Free plan', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 18:05', 'UTC'));
    $this->artisan('manager:daily-summary');

    expect($this->whatsapp->to('+2348020000001', 'daily_summary'))->toBe([]);
});
