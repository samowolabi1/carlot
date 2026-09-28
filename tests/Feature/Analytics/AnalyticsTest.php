<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Analytics\Models\DailyVehicleStat;
use App\Domain\Analytics\Support\PricingGuide;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use App\Domain\Sharing\Models\ShareLink;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id')]);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_250_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Ada Obi']);
    $this->carPath = substr($this->car->publicPath(), 5);
    $this->browser = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'];
});

it('counts a view once per visitor in 30 minutes, and skips bots and the lot\'s own staff', function () {
    $this->get(route('cars.show', $this->carPath), $this->browser)->assertOk();
    $this->get(route('cars.show', $this->carPath), $this->browser)->assertOk();
    $this->get(route('cars.show', $this->carPath), ['HTTP_USER_AGENT' => 'WhatsApp/2.23.20.0'])->assertOk();
    $this->get(route('cars.show', $this->carPath), ['HTTP_USER_AGENT' => 'Googlebot/2.1'])->assertOk();
    $this->actingAs($this->owner)->get(route('cars.show', $this->carPath), $this->browser)->assertOk();

    expect(AnalyticsEvent::where('type', 'view')->count())->toBe(1);

    $this->travel(31)->minutes();
    auth()->logout();
    $this->get(route('cars.show', $this->carPath), $this->browser);
    expect(AnalyticsEvent::where('type', 'view')->count())->toBe(2);
});

it('tracks saves, shares, leads and bookings, and attributes views from share links', function () {
    $this->actingAs($this->buyer)->post(route('favourites.store', $this->car->ulid));
    $this->actingAs($this->buyer)->post(route('favourites.store', $this->car->ulid)); // already saved
    $this->actingAs($this->buyer)->postJson(route('shares.store'), ['vehicle' => $this->car->ulid, 'platform' => 'whatsapp'])->assertOk();
    app(CaptureLead::class)->run($this->lot, $this->buyer, LeadSource::Chat, $this->car);

    $code = ShareLink::sole()->code;
    auth()->logout();
    $this->get(route('cars.show', $this->carPath).'?ref='.$code, $this->browser);

    expect(AnalyticsEvent::orderBy('id')->get(['type', 'channel'])->map(fn ($e) => $e->type.':'.$e->channel)->all())
        ->toBe(['save:', 'share:whatsapp', 'lead:chat', 'view:whatsapp']);
});

it('rolls events up per car per local day, idempotently', function () {
    foreach (['view', 'view', 'save', 'lead'] as $type) {
        AnalyticsEvent::create(['lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'type' => $type, 'occurred_at' => now()]);
    }
    // 23:30 UTC on the 4th is 00:30 on the 5th in Lagos.
    AnalyticsEvent::create(['lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'type' => 'view', 'occurred_at' => CarbonImmutable::parse('2026-10-04 23:30', 'UTC')]);
    AnalyticsEvent::create(['lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'type' => 'view', 'occurred_at' => CarbonImmutable::parse('2026-10-04 22:30', 'UTC')]);

    $this->artisan('stats:rollup');
    $this->artisan('stats:rollup');

    $today = DailyVehicleStat::withoutGlobalScopes()->where('date', '2026-10-05')->sole();
    expect($today)->views->toBe(3)->saves->toBe(1)->leads->toBe(1)
        ->and(DailyVehicleStat::withoutGlobalScopes()->where('date', '2026-10-04')->sole()->views)->toBe(1);

    // Raw events older than 90 days go; rollups stay.
    AnalyticsEvent::create(['lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'type' => 'view', 'occurred_at' => now()->subDays(91)]);
    $this->artisan('stats:rollup');
    expect(AnalyticsEvent::where('occurred_at', '<', now()->subDays(90))->count())->toBe(0);
});

it('shows the analytics page from the rollups, with ageing flags', function () {
    $old = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'listed_at' => now()->subDays(92)]);
    DailyVehicleStat::withoutGlobalScopes()->insert([
        ['vehicle_id' => $this->car->id, 'lot_id' => $this->lot->id, 'date' => '2026-10-04', 'views' => 120, 'saves' => 4, 'shares' => 2, 'leads' => 3, 'bookings' => 1],
        ['vehicle_id' => $old->id, 'lot_id' => $this->lot->id, 'date' => '2026-10-04', 'views' => 12, 'saves' => 0, 'shares' => 0, 'leads' => 0, 'bookings' => 0],
        // The previous period, for the change figure.
        ['vehicle_id' => $this->car->id, 'lot_id' => $this->lot->id, 'date' => '2026-08-20', 'views' => 66, 'saves' => 0, 'shares' => 0, 'leads' => 0, 'bookings' => 0],
    ]);
    app(CaptureLead::class)->run($this->lot, $this->buyer, LeadSource::WhatsApp, $this->car);

    $this->actingAs($this->owner)->get(route('dealer.analytics', [$this->lot, 'period' => '30d']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Analytics')
            ->where('data.kpis.0.value', 132)
            ->where('data.kpis.0.change', 100)
            ->where('data.kpis.1.value', 1)
            ->where('data.funnel.1.note', '0.76% of views')
            ->where('data.cars.0.views', 120)
            ->where('data.cars.1.flag', 'stale')
            ->where('data.sources.0.label', 'WhatsApp')
            ->has('data.staff')
            ->has('data.trend', 30));
});

it('keeps analytics to owners and managers, basics on Starter and the rest on Pro', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->get(route('dealer.analytics', $this->lot))->assertForbidden();

    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    $this->actingAs($this->owner)->get(route('dealer.analytics', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('allowed', true)->where('full', false)->where('data.staff', null)->where('data.sources', null));
    $this->actingAs($this->owner)->get(route('dealer.analytics', [$this->lot, 'export' => 'stock']))->assertForbidden();

    $this->lot->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
    $this->actingAs($this->owner)->get(route('dealer.analytics', $this->lot))->assertInertia(fn (Assert $page) => $page->where('allowed', false)->where('data', null));
});

it('exports stock, leads and sales as CSV on Pro', function () {
    app(CaptureLead::class)->run($this->lot, $this->buyer, LeadSource::Chat, $this->car);

    $stock = $this->actingAs($this->owner)->get(route('dealer.analytics', [$this->lot, 'export' => 'stock']));
    $stock->assertOk();
    expect($stock->headers->get('content-type'))->toContain('text/csv')
        ->and($stock->streamedContent())->toContain('Car,Status,"Price (NGN)"')->toContain($this->car->title())->toContain('12500000');

    expect($this->actingAs($this->owner)->get(route('dealer.analytics', [$this->lot, 'export' => 'leads']))->streamedContent())->toContain('Ada Obi')->toContain('Chat');
});

it('gives a pricing guide with 5 or more comparables, year ±1', function () {
    $make = ['make_id' => $this->car->make_id, 'vehicle_model_id' => $this->car->vehicle_model_id];
    $other = app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $other->update(['status' => 'active']);
    foreach ([1_100_000_000, 1_200_000_000, 1_300_000_000, 1_400_000_000] as $price) {
        Vehicle::factory()->available()->create(['lot_id' => $other->id, ...$make, 'year' => $this->car->year + 1, 'price' => $price]);
    }
    Vehicle::factory()->available()->create(['lot_id' => $other->id, ...$make, 'year' => $this->car->year + 3, 'price' => 900_000_000]); // too new

    expect(PricingGuide::for($this->car))->toBeNull();

    Vehicle::factory()->available()->create(['lot_id' => $other->id, ...$make, 'year' => $this->car->year - 1, 'price' => 1_500_000_000]);
    expect(PricingGuide::for($this->car))->toBe(['low' => 1_100_000_000, 'median' => 1_300_000_000, 'high' => 1_500_000_000, 'count' => 5]);

    $this->actingAs($this->owner)->get(route('dealer.vehicles.edit', [$this->lot, $this->car, 'price']))
        ->assertInertia(fn (Assert $page) => $page->where('guide.median', 13_000_000)->where('guide.count', 5));
});

it('keeps analytics inside their lot', function () {
    DailyVehicleStat::withoutGlobalScopes()->insert(['vehicle_id' => $this->car->id, 'lot_id' => $this->lot->id, 'date' => '2026-10-04', 'views' => 50, 'saves' => 0, 'shares' => 0, 'leads' => 0, 'bookings' => 0]);
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $otherLot->update(['plan_id' => Plan::where('code', 'pro')->value('id')]);

    $this->actingAs($otherOwner)->get(route('dealer.analytics', $otherLot))
        ->assertInertia(fn (Assert $page) => $page->where('data.kpis.0.value', 0)->has('data.cars', 0));
    $this->actingAs($otherOwner)->get(route('dealer.analytics', $this->lot))->assertForbidden();
    expect($this->actingAs($otherOwner)->get(route('dealer.analytics', [$otherLot, 'export' => 'stock']))->streamedContent())->not->toContain($this->car->title());
});
