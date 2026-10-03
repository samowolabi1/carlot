<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\CreateOrder;
use App\Domain\LotManager\Actions\RecordPayment;
use App\Domain\Lots\Actions\CreateLot;
use Inertia\Testing\AssertableInertia as Assert;

it('needs signing in', function () {
    $this->get(route('account'))->assertRedirect(route('login'));
});

it('shows the budget, counts and orders a seller recorded for this phone', function () {
    $this->travelTo('2026-10-05 10:00'); // order numbers include the year
    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors']);
    $car = Vehicle::factory()->available()->create(['lot_id' => $lot->id, 'price' => 1_000_000_000]);
    $order = app(CreateOrder::class)->run($lot, $owner, ['vehicle' => $car->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101']);
    app(RecordPayment::class)->run($order, $owner, ['amount' => 200_000_000, 'method' => 'cash']);

    // Ada signs up to CarYard later with the same number.
    $ada = User::factory()->create(['name' => 'Ada Obi', 'phone' => '+2348035550101']);
    $stranger = User::factory()->create(['phone' => '+2348035550999']);

    $this->actingAs($ada)->get(route('account'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Index')
            ->where('profile.initials', 'AO')
            ->has('orders', 1)
            ->where('orders.0.order_no', 'PM-2026-00001')
            ->where('orders.0.lot', 'Prime Motors')
            ->where('orders.0.balance', '₦8,000,000')
            ->where('orders.0.url', fn (string $url) => str_contains($url, '/o/'.$order->ulid.'?signature='))
            ->where('lots', []));

    $this->actingAs($stranger)->get(route('account'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));
    $this->actingAs($owner)->get(route('account'))->assertInertia(fn (Assert $page) => $page->where('lots.0.name', 'Prime Motors'));
});
