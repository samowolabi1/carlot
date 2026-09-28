<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\DocumentStatus;
use App\Domain\LotManager\Models\OrderDocument;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\VehicleCost;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['plan_id' => Plan::where('code', 'pro')->value('id')]);
    $this->car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);
    $this->sales = User::factory()->staff()->create();
    $this->lot->members()->attach($this->sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101', 'agreed_price' => '9,800,000', 'discount' => '200,000',
    ]);
    $this->order = SalesOrder::withoutGlobalScopes()->sole();
    $this->doc = fn (string $type) => OrderDocument::withoutGlobalScopes()->where('sales_order_id', $this->order->id)->where('type', $type)->sole();
    $this->cost = fn (array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->owner)->post(route('dealer.vehicles.costs.store', [$this->lot, $this->car]), [
        'type' => 'purchase', 'amount' => '₦7,500,000', 'incurred_at' => '2026-09-20', 'supplier' => 'Cotonou auction', ...$data,
    ]);
});

it('gives every order the standard papers checklist', function () {
    $docs = OrderDocument::withoutGlobalScopes()->where('sales_order_id', $this->order->id)->orderBy('id')->get();

    expect($docs->pluck('type')->map->value->all())->toBe(['customs_papers', 'proof_of_ownership', 'plate_number', 'registration', 'spare_key'])
        ->and($docs->where('mandatory', true)->count())->toBe(3);
});

it('waits for the required papers before "papers ready", and hands them over on delivery', function () {
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.payments.store', [$this->lot, $this->order]), ['amount' => '9,600,000', 'method' => 'transfer']);
    $update = fn (string $type, string $status, array $extra = []) => $this->actingAs($this->sales)
        ->post(route('dealer.manager.orders.documents.update', [$this->lot, $this->order, ($this->doc)($type)]), ['status' => $status, ...$extra]);

    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $this->order]), ['status' => 'papers_ready'])
        ->assertSessionHasErrors(['status' => 'Still waiting for: Customs papers, Proof of ownership, Plate number.']);

    $update('customs_papers', 'received', ['file' => UploadedFile::fake()->create('customs.pdf', 200, 'application/pdf')])->assertSessionHasNoErrors();
    $update('proof_of_ownership', 'received');
    $update('plate_number', 'received');

    $customs = ($this->doc)('customs_papers');
    Storage::disk('local')->assertExists($customs->file_path);
    $this->actingAs($this->sales)->get(route('dealer.manager.orders.documents.file', [$this->lot, $this->order, $customs]))->assertOk();

    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $this->order]), ['status' => 'papers_ready'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $this->order]), ['status' => 'delivered'])->assertSessionHasNoErrors();

    expect(($this->doc)('plate_number')->status)->toBe(DocumentStatus::HandedOver)
        ->and(($this->doc)('spare_key')->status)->toBe(DocumentStatus::Pending);

    $this->get(URL::signedRoute('orders.track', ['order' => $this->order->ulid]))
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.status_label', 'Handed over'));
});

it('adds and removes extra checklist items', function () {
    $this->actingAs($this->sales)->post(route('dealer.manager.orders.documents.store', [$this->lot, $this->order]), ['label' => 'Service book'])->assertSessionHasNoErrors();
    $extra = OrderDocument::withoutGlobalScopes()->where('label', 'Service book')->sole();

    $this->actingAs($this->sales)->delete(route('dealer.manager.orders.documents.destroy', [$this->lot, $this->order, ($this->doc)('spare_key')]))->assertSessionHasErrors('document');
    $this->actingAs($this->sales)->delete(route('dealer.manager.orders.documents.destroy', [$this->lot, $this->order, $extra]))->assertSessionHasNoErrors();
    expect(OrderDocument::withoutGlobalScopes()->count())->toBe(5);
});

it('records car costs and shows profit to owners and managers only', function () {
    ($this->cost)(['receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();
    ($this->cost)(['type' => 'repair', 'amount' => '400,000', 'supplier' => null]);

    expect(VehicleCost::withoutGlobalScopes()->sum('amount'))->toEqual(790_000_000);

    $this->actingAs($this->owner)->get(route('dealer.vehicles.costs.index', [$this->lot, $this->car]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Vehicles/Costs')
            ->has('costs', 2)
            ->where('total', '₦7,900,000')
            ->where('profit.profit', '₦1,700,000')
            ->where('profit.margin', 17.7));

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.show', [$this->lot, $this->order]))
        ->assertInertia(fn (Assert $page) => $page->where('profit.revenue', '₦9,600,000')->where('profit.costs', '₦7,900,000')->where('profit.profit', '₦1,700,000'));

    // The sales role never sees costs or profit.
    $this->actingAs($this->sales)->get(route('dealer.manager.orders.show', [$this->lot, $this->order]))->assertInertia(fn (Assert $page) => $page->where('profit', null));
    $this->actingAs($this->sales)->get(route('dealer.vehicles.costs.index', [$this->lot, $this->car]))->assertForbidden();
    ($this->cost)([], $this->sales)->assertForbidden();
    $this->actingAs($this->sales)->get(route('dealer.vehicles.index', $this->lot))->assertInertia(fn (Assert $page) => $page->where('currentLot.can_costs', false));

    $cost = VehicleCost::withoutGlobalScopes()->orderBy('id')->first();
    $this->actingAs($this->sales)->get(route('dealer.vehicles.costs.receipt', [$this->lot, $this->car, $cost]))->assertForbidden();
    $this->actingAs($this->owner)->get(route('dealer.vehicles.costs.receipt', [$this->lot, $this->car, $cost]))->assertOk();

    // Costs never reach the public car page.
    $this->lot->update(['status' => 'active']);
    $this->get(route('cars.show', substr($this->car->publicPath(), 5)))->assertDontSee('7,900,000')->assertDontSee('Cotonou');
});

it('keeps costs to the Pro plan', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    $this->actingAs($this->owner)->get(route('dealer.vehicles.costs.index', [$this->lot, $this->car]))->assertForbidden();
});

it('removes a cost', function () {
    ($this->cost)();
    $cost = VehicleCost::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->delete(route('dealer.vehicles.costs.destroy', [$this->lot, $this->car, $cost]))->assertSessionHasNoErrors();
    expect(VehicleCost::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps papers and costs inside their lot', function () {
    ($this->cost)();
    $cost = VehicleCost::withoutGlobalScopes()->sole();
    $doc = ($this->doc)('customs_papers');
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $otherLot->update(['plan_id' => Plan::where('code', 'pro')->value('id')]);

    $this->actingAs($otherOwner)->get(route('dealer.vehicles.costs.index', [$otherLot, $this->car]))->assertNotFound();
    $this->actingAs($otherOwner)->delete(route('dealer.vehicles.costs.destroy', [$otherLot, $this->car, $cost]))->assertNotFound();
    $this->actingAs($otherOwner)->post(route('dealer.manager.orders.documents.update', [$otherLot, $this->order, $doc]), ['status' => 'received'])->assertNotFound();
    $this->actingAs($otherOwner)->get(route('dealer.vehicles.costs.index', [$this->lot, $this->car]))->assertForbidden();
});
