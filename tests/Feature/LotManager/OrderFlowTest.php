<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\RecordPayment;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderDocument;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]); // ₦10m

    $this->order = function (array $data = [], ?User $as = null): SalesOrder {
        $this->actingAs($as ?? $this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
            'vehicle' => $this->car->ulid,
            'name' => 'Ada Obi',
            'phone' => '08035550101',
            'consent_whatsapp' => true,
            ...$data,
        ])->assertSessionHasNoErrors();

        return SalesOrder::withoutGlobalScopes()->latest('id')->firstOrFail();
    };
    $this->pay = fn (SalesOrder $order, string $amount, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->post(route('dealer.manager.orders.payments.store', [$this->lot, $order]), ['amount' => $amount, 'method' => 'transfer', ...$data]);
});

it('creates an order at the list price with a per-lot number', function () {
    $order = ($this->order)();

    expect($order->order_no)->toBe('PM-2026-00001')
        ->and($order->agreed_price)->toBe(1_000_000_000)
        ->and($order->balance)->toBe(1_000_000_000)
        ->and($order->status)->toBe(OrderStatus::Draft)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and(LotCustomer::withoutGlobalScopes()->sole()->phone)->toBe('+2348035550101');
});

it('takes the agreed price and discount in naira', function () {
    $order = ($this->order)(['agreed_price' => '₦9,500,000', 'discount' => '200,000']);

    expect($order->agreed_price)->toBe(950_000_000)
        ->and($order->discount)->toBe(20_000_000)
        ->and($order->balance)->toBe(930_000_000);
});

it('only orders an available or reserved car that is not on another order', function () {
    ($this->order)();

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'name' => 'Bola', 'phone' => '08035550202'])
        ->assertSessionHasErrors(['vehicle' => 'This car is already on order PM-2026-00001.']);

    $draft = Vehicle::factory()->create(['lot_id' => $this->lot->id]);
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $draft->ulid, 'name' => 'Bola', 'phone' => '08035550202'])
        ->assertSessionHasErrors('vehicle');

    $theirs = Vehicle::factory()->available()->create();
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $theirs->ulid, 'name' => 'Bola', 'phone' => '08035550202'])
        ->assertSessionHasErrors('vehicle');
});

it('limits Free lots to 10 open orders', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
    $customer = LotCustomer::withoutGlobalScopes()->create(['lot_id' => $this->lot->id, 'name' => 'Ada', 'phone' => '+2348035550101']);

    foreach (range(1, 10) as $i) {
        $car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]);
        SalesOrder::withoutGlobalScopes()->create([
            'lot_id' => $this->lot->id, 'order_no' => "X-{$i}", 'lot_customer_id' => $customer->id, 'vehicle_id' => $car->id,
            'list_price' => 1, 'agreed_price' => 1, 'total_paid' => 0, 'balance' => 1, 'currency' => 'NGN', 'status' => OrderStatus::Draft,
        ]);
    }

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'customer' => $customer->ulid])
        ->assertSessionHasErrors(['vehicle' => 'Your plan allows 10 open orders. Deliver or cancel one, or upgrade your plan.']);

    SalesOrder::withoutGlobalScopes()->where('order_no', 'X-1')->update(['status' => OrderStatus::Delivered]);
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'customer' => $customer->ulid])
        ->assertSessionHasNoErrors();
});

it('moves the order and the car along as payments come in', function () {
    $order = ($this->order)();

    ($this->pay)($order, '₦2,000,000')->assertSessionHasNoErrors();
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::DepositPaid)
        ->and($order->total_paid)->toBe(200_000_000)
        ->and($order->balance)->toBe(800_000_000)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved);

    ($this->pay)($order, '8000000', ['method' => 'cash'])->assertSessionHasNoErrors();
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::FullyPaid)
        ->and($order->balance)->toBe(0);

    expect(OrderPayment::query()->orderBy('id')->pluck('receipt_no')->all())->toBe(['PM-2026-00001', 'PM-2026-00002'])
        ->and(AuditLog::where('action', 'payment.recorded')->count())->toBe(2);
});

it('numbers receipts per lot', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1000000');

    $other = app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Autoworld']);
    $otherCar = Vehicle::factory()->available()->create(['lot_id' => $other->id]);
    $this->actingAs($other->owner)->post(route('dealer.manager.orders.store', $other), ['vehicle' => $otherCar->ulid, 'name' => 'Bola', 'phone' => '08035550202'])->assertSessionHasNoErrors();
    $otherOrder = SalesOrder::withoutGlobalScopes()->where('lot_id', $other->id)->sole();
    $this->actingAs($other->owner)->post(route('dealer.manager.orders.payments.store', [$other, $otherOrder]), ['amount' => '500000', 'method' => 'cash'])->assertSessionHasNoErrors();

    expect($otherOrder->order_no)->toBe('AUT-2026-00001')
        ->and(OrderPayment::where('lot_id', $other->id)->value('receipt_no'))->toBe('AUT-2026-00001');
});

it('keeps the balance right when two payments land at once', function () {
    $order = ($this->order)();
    $pay = app(RecordPayment::class);

    // Two staff with the same (stale) copy of the order each record a payment.
    $a = SalesOrder::withoutGlobalScopes()->find($order->id);
    $b = SalesOrder::withoutGlobalScopes()->find($order->id);
    $pay->run($a, $this->owner, ['amount' => 300_000_000, 'method' => 'cash']);
    $pay->run($b, $this->owner, ['amount' => 200_000_000, 'method' => 'transfer']);

    $order->refresh();
    expect($order->total_paid)->toBe(500_000_000)
        ->and($order->balance)->toBe(500_000_000)
        ->and($b->balance)->toBe(500_000_000);
});

it('sends the receipt on WhatsApp only with consent', function () {
    $order = ($this->order)();
    ($this->pay)($order, '2000000');

    $sent = $this->whatsapp->to('+2348035550101', 'payment_receipt');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params)->toBe(['Ada Obi', '₦2,000,000', 'Prime Motors', $this->car->title(), 'PM-2026-00001', '₦8,000,000 left to pay'])
        ->and($sent[0]->buttonSuffix)->toStartWith('o/'.$order->ulid.'?signature=');

    $car2 = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]);
    $quiet = ($this->order)(['vehicle' => $car2->ulid, 'phone' => '08035550202', 'consent_whatsapp' => false]);
    ($this->pay)($quiet, '1000000');

    expect($this->whatsapp->to('+2348035550202'))->toBe([])
        ->and(array_filter($this->sms->sent, fn ($s) => $s['to'] === '+2348035550202'))->toBe([]);
});

it('rejects payments on a closed order and non-positive amounts', function () {
    $order = ($this->order)();
    ($this->pay)($order, '0')->assertSessionHasErrors('amount');

    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    ($this->pay)($order, '1000')->assertSessionHasErrors('amount');
});

it('voids a payment with a reason and reruns the totals', function () {
    $order = ($this->order)();
    ($this->pay)($order, '2000000');
    $payment = OrderPayment::sole();

    $this->actingAs($this->owner)->post(route('dealer.manager.payments.void', [$this->lot, $payment]), ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

    $order->refresh();
    expect($payment->fresh()->isVoid())->toBeTrue()
        ->and($order->total_paid)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Draft)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and(AuditLog::where('action', 'payment.voided')->sole()->changes['reason'])->toBe('Entered twice');
});

it('hands over a paid car and marks it sold', function () {
    $order = ($this->order)();
    ($this->pay)($order, '10000000');

    // Papers ready waits for the required papers (S10).
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'papers_ready'])->assertSessionHasErrors('status');
    OrderDocument::withoutGlobalScopes()->where('sales_order_id', $order->id)->where('mandatory', true)->update(['status' => 'received', 'received_at' => now()]);

    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'papers_ready'])->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe(OrderStatus::PapersReady)
        ->and($this->whatsapp->to('+2348035550101', 'order_update'))->toHaveCount(1);

    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered'])->assertSessionHasNoErrors();
    $order->refresh();
    $car = $this->car->fresh();
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->delivered_at)->not->toBeNull()
        ->and($car->status)->toBe(VehicleStatus::Sold)
        ->and($car->sold_at)->not->toBeNull()
        ->and($car->isOnMarketplace())->toBeFalse();
});

it('needs the owner and a reason to hand over before full payment', function () {
    $manager = User::factory()->staff()->create();
    $this->lot->members()->attach($manager, ['role' => LotRole::Manager->value, 'accepted_at' => now()]);
    $order = ($this->order)();
    ($this->pay)($order, '5000000');

    $this->actingAs($manager)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered'])->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered'])->assertSessionHasErrors('override_reason');
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered', 'override_reason' => 'Balance on Friday'])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and(AuditLog::where('action', 'order.delivered_with_balance')->sole()->changes)->toEqual(['balance' => 500_000_000, 'reason' => 'Balance on Friday']);
});

it('refuses status jumps the flow does not allow', function () {
    $order = ($this->order)();

    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'papers_ready'])->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered', 'override_reason' => 'x'])->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'fully_paid'])->assertSessionHasErrors('status');
});

it('cancels with a refund or credit and frees the car', function (string $money) {
    $order = ($this->order)();
    ($this->pay)($order, '2000000');

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.cancel', [$this->lot, $order]), ['reason' => 'Changed mind'])->assertSessionHasErrors('money');
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.cancel', [$this->lot, $order]), ['reason' => 'Changed mind', 'money' => $money, 'refund_method' => 'transfer'])->assertSessionHasNoErrors();

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available);

    if ($money === 'refund') {
        expect(OrderPayment::where('amount', '<', 0)->sole()->amount)->toBe(-200_000_000)
            ->and($order->total_paid)->toBe(0);
    } else {
        expect($order->total_paid)->toBe(200_000_000)
            ->and($order->notes)->toContain('Kept ₦2,000,000 as credit.');
    }
})->with(['refund', 'credit']);

it('lets sales staff take payments but not void them or cancel orders', function () {
    $rep = User::factory()->staff()->create();
    $this->lot->members()->attach($rep, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $order = ($this->order)([], $rep);

    ($this->pay)($order, '1000000', [], $rep)->assertSessionHasNoErrors();
    $payment = OrderPayment::sole();

    $this->actingAs($rep)->post(route('dealer.manager.payments.void', [$this->lot, $payment]), ['reason' => 'x'])->assertForbidden();
    $this->actingAs($rep)->post(route('dealer.manager.orders.cancel', [$this->lot, $order]), ['reason' => 'x', 'money' => 'credit'])->assertForbidden();
});

it('downloads a numbered PDF receipt', function () {
    $order = ($this->order)();
    ($this->pay)($order, '2000000', ['reference' => 'TRF-889']);
    $payment = OrderPayment::sole();

    $response = $this->actingAs($this->owner)->get(route('dealer.manager.orders.receipt', [$this->lot, $order, $payment]));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('opens a prefilled order from "Mark sold" on the stock list', function () {
    $this->actingAs($this->owner)->get(route('dealer.vehicles.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('vehicles.data.0.order', null));

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.create', ['lot' => $this->lot, 'vehicle' => $this->car->ulid]))
        ->assertInertia(fn (Assert $page) => $page->component('Dealer/Manager/OrderCreate')->where('vehicle', $this->car->ulid));

    $order = ($this->order)();
    $this->actingAs($this->owner)->get(route('dealer.vehicles.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('vehicles.data.0.order.order_no', $order->order_no));
});

it('will not release a reserved car that an order has paid for', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1000000');

    $this->actingAs($this->owner)->patch(route('dealer.vehicles.status', [$this->lot, $this->car]), ['status' => 'available'])
        ->assertSessionHasErrors(['status' => 'Order PM-2026-00001 holds this car. Cancel the order in Sales Manager to release it.']);
});

it('shows the order page with what each role can do', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1000000');

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.show', [$this->lot, $order]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Manager/Order')
            ->where('order.balance', '₦9,000,000')
            ->has('payments', 1)
            ->where('can.void', true)
            ->where('can.deliver', true)
            ->where('steps.1.done', true)
            ->where('steps.2.done', false));

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('counts.open', 1));
});

it('does not take more than the balance', function () {
    $order = ($this->order)();
    ($this->pay)($order, '10000001')->assertSessionHasErrors(['amount' => 'That is more than the ₦10,000,000 still to pay.']);
    ($this->pay)($order, '10000000')->assertSessionHasNoErrors();
    ($this->pay)($order, '1')->assertSessionHasErrors(['amount' => 'This order is already fully paid.']);
});
