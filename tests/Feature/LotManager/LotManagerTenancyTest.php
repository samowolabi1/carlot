<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\CreateOrder;
use App\Domain\LotManager\Actions\RecordPayment;
use App\Domain\LotManager\Actions\RecordWalkIn;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Support\CurrentLot;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->mine = app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Prime Motors']);
    $this->theirs = app(CreateLot::class)->run($owner = User::factory()->staff()->create(), ['name' => 'Autoworld']);

    $car = Vehicle::factory()->available()->create(['lot_id' => $this->theirs->id]);
    app(RecordWalkIn::class)->run($this->theirs, $owner, ['name' => 'Ada', 'phone' => '08035550101', 'next_step' => 'call_back']);
    $this->order = app(CreateOrder::class)->run($this->theirs, $owner, ['vehicle' => $car->ulid, 'name' => 'Ada', 'phone' => '08035550101']);
    $this->payment = app(RecordPayment::class)->run($this->order, $owner, ['amount' => 100_000, 'method' => 'cash']);
    $this->customer = LotCustomer::withoutGlobalScopes()->sole();
    $this->task = FollowUpTask::withoutGlobalScopes()->sole();
});

it('hides another lot\'s customers, orders, payments and tasks behind 404s', function (string $method, string $name, Closure $params) {
    $this->actingAs($this->mine->owner)
        ->{$method}(route($name, ['lot' => $this->mine, ...$params($this)]), ['reason' => 'x', 'status' => 'delivered', 'action' => 'done', 'amount' => '1000', 'method' => 'cash', 'name' => 'x', 'source' => 'walk_in'])
        ->assertNotFound();
})->with([
    'customer page' => ['get', 'dealer.manager.customers.show', fn ($t) => ['customer' => $t->customer]],
    'customer edit' => ['patch', 'dealer.manager.customers.update', fn ($t) => ['customer' => $t->customer]],
    'order page' => ['get', 'dealer.manager.orders.show', fn ($t) => ['order' => $t->order]],
    'order status' => ['patch', 'dealer.manager.orders.update', fn ($t) => ['order' => $t->order]],
    'order cancel' => ['post', 'dealer.manager.orders.cancel', fn ($t) => ['order' => $t->order]],
    'payment' => ['post', 'dealer.manager.orders.payments.store', fn ($t) => ['order' => $t->order]],
    'receipt' => ['get', 'dealer.manager.orders.receipt', fn ($t) => ['order' => $t->order, 'payment' => $t->payment]],
    'void' => ['post', 'dealer.manager.payments.void', fn ($t) => ['payment' => $t->payment]],
    'task' => ['patch', 'dealer.manager.tasks.update', fn ($t) => ['task' => $t->task]],
]);

it('forbids another lot\'s Lot Manager pages outright', function (string $name) {
    $this->actingAs($this->mine->owner)->get(route($name, $this->theirs))->assertForbidden();
})->with(['dealer.manager.today', 'dealer.manager.walk-ins.index', 'dealer.manager.customers.index', 'dealer.manager.orders.index', 'dealer.manager.orders.create']);

it('scopes lists and queries to the current lot', function () {
    app(CurrentLot::class)->set($this->mine);

    expect(LotCustomer::count())->toBe(0)
        ->and(WalkIn::count())->toBe(0)
        ->and(SalesOrder::count())->toBe(0)
        ->and(FollowUpTask::count())->toBe(0);
});

it('does not sync into a lot the user is not a member of', function () {
    $this->actingAs($this->mine->owner)->postJson(route('dealer.manager.sync', $this->theirs), [
        'items' => [['type' => 'walk_in', 'client_uuid' => (string) Str::uuid(), 'data' => ['name' => 'X', 'phone' => '08035550999']]],
    ])->assertForbidden();
});

it('does not record a payment against another lot\'s order through sync', function () {
    $results = $this->actingAs($this->mine->owner)->postJson(route('dealer.manager.sync', $this->mine), [
        'items' => [['type' => 'payment', 'client_uuid' => (string) Str::uuid(), 'data' => ['order' => $this->order->ulid, 'amount' => 5000, 'method' => 'cash']]],
    ])->json('results');

    expect($results[0]['status'])->toBe('failed')
        ->and($this->order->fresh()->total_paid)->toBe(100_000);
});

it('does not let one lot order another lot\'s car', function () {
    $car = Vehicle::factory()->available()->create(['lot_id' => $this->theirs->id]);

    $this->actingAs($this->mine->owner)->post(route('dealer.manager.orders.store', $this->mine), ['vehicle' => $car->ulid, 'name' => 'X', 'phone' => '08035550999'])
        ->assertSessionHasErrors('vehicle');
});
