<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\CreateOrder;
use App\Domain\LotManager\Actions\RecordPayment;
use App\Domain\LotManager\Actions\VoidPayment;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Actions\CreateLot;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);
});

describe('order tracking page', function () {
    beforeEach(function () {
        $this->order = app(CreateOrder::class)->run($this->lot, $this->owner, ['vehicle' => $this->car->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101']);
        $this->payment = app(RecordPayment::class)->run($this->order, $this->owner, ['amount' => 250_000_000, 'method' => 'cash']);
    });

    it('needs the signed link', function () {
        $this->get('/o/'.$this->order->ulid)->assertForbidden();
        $this->get('/o/'.$this->order->ulid.'?signature=forged')->assertForbidden();
    });

    it('shows status, payments and balance without signing in', function () {
        $this->get(OrderLinks::track($this->order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Track')
                ->where('order.order_no', 'PM-2026-00001')
                ->where('order.customer', 'Ada')
                ->where('order.status_label', 'Deposit paid')
                ->where('order.balance', '₦7,500,000')
                ->has('payments', 1)
                ->where('lot.name', 'Prime Motors')
                ->missing('order.staff')
                ->where('poweredBy', fn (string $url) => str_contains($url, 'utm_medium=order_tracking')));
    });

    it('serves receipts from signed links only, and never void ones', function () {
        $this->get(OrderLinks::receipt($this->payment, $this->order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('orders.receipt', [$this->order->ulid, $this->payment->ulid]))->assertForbidden();

        app(VoidPayment::class)->run($this->payment, $this->owner, 'Wrong amount');
        $this->get(OrderLinks::receipt($this->payment, $this->order))->assertNotFound();
    });

    it('does not open another order\'s receipt with a signed link', function () {
        $other = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]);
        $otherOrder = app(CreateOrder::class)->run($this->lot, $this->owner, ['vehicle' => $other->ulid, 'name' => 'Bola', 'phone' => '08035550202']);

        $this->get(URL::signedRoute('orders.receipt', ['order' => $otherOrder->ulid, 'payment' => $this->payment->ulid]))->assertNotFound();
    });
});

describe('offline sync', function () {
    beforeEach(function () {
        $this->batch = [
            'items' => [
                ['type' => 'walk_in', 'client_uuid' => (string) Str::uuid(), 'data' => ['name' => 'Ada Obi', 'phone' => '08035550101', 'interest' => 'serious', 'budget_max' => 9000000, 'visited_at' => now()->subHour()->toIso8601String()]],
                ['type' => 'order', 'client_uuid' => $orderUuid = (string) Str::uuid(), 'data' => ['vehicle' => $this->car->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101', 'agreed_price' => 9800000]],
                ['type' => 'payment', 'client_uuid' => (string) Str::uuid(), 'data' => ['order' => $orderUuid, 'amount' => 1000000, 'method' => 'cash']],
                ['type' => 'walk_in', 'client_uuid' => (string) Str::uuid(), 'data' => ['name' => 'Nobody', 'phone' => '123']],
            ],
        ];
    });

    it('replays a queue in order and reports each item', function () {
        $results = $this->actingAs($this->owner)->postJson(route('dealer.manager.sync', $this->lot), $this->batch)
            ->assertOk()
            ->json('results');

        expect(array_column($results, 'status'))->toBe(['ok', 'ok', 'ok', 'failed'])
            ->and($results[3]['message'])->toBe('Phone number must look like 0803 123 4567 or +234 803 123 4567.');

        $order = SalesOrder::withoutGlobalScopes()->sole();
        expect($order->agreed_price)->toBe(980_000_000)
            ->and($order->status)->toBe(OrderStatus::DepositPaid)
            ->and($order->total_paid)->toBe(100_000_000)
            ->and(LotCustomer::withoutGlobalScopes()->sole()->budget_max)->toBe(900_000_000);
    });

    it('is idempotent: the same client_uuid twice gives one row', function () {
        $this->actingAs($this->owner)->postJson(route('dealer.manager.sync', $this->lot), $this->batch)->assertOk();
        $again = $this->actingAs($this->owner)->postJson(route('dealer.manager.sync', $this->lot), $this->batch)->assertOk()->json('results');

        expect(array_column($again, 'status'))->toBe(['ok', 'ok', 'ok', 'failed'])
            ->and(WalkIn::withoutGlobalScopes()->count())->toBe(1)
            ->and(SalesOrder::withoutGlobalScopes()->count())->toBe(1)
            ->and(OrderPayment::count())->toBe(1)
            ->and(SalesOrder::withoutGlobalScopes()->sole()->total_paid)->toBe(100_000_000);
    });

    it('rejects a malformed batch', function () {
        $this->actingAs($this->owner)->postJson(route('dealer.manager.sync', $this->lot), ['items' => [['type' => 'sale', 'client_uuid' => 'x', 'data' => []]]])
            ->assertUnprocessable();
    });
});
