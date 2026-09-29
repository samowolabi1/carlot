<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id'), 'reservation_deposit' => 25_000_000]); // ₦250,000
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_250_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);
    LotBankAccount::withoutGlobalScopes()->create(['lot_id' => $this->lot->id, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Prime Motors Ltd', 'is_default' => true]);

    $this->reserve = function (int $hours = 48, ?User $as = null) {
        return $this->actingAs($as ?? $this->buyer)->post(route('reservations.store', $this->car->ulid), ['hours' => $hours]);
    };
    // The lot confirms the buyer's transfer reached its account.
    $this->confirm = function (?Reservation $reservation = null) {
        $reservation ??= Reservation::withoutGlobalScopes()->where('customer_id', $this->buyer->id)->latest('id')->firstOrFail();

        return $this->actingAs($this->owner)->post(route('dealer.reservations.confirm', [$this->lot, $reservation]));
    };
});

it('explains the reserve flow with the deposit and hold times', function () {
    $this->actingAs($this->buyer)->get(route('reservations.create', $this->car->ulid))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deals/Reserve')
            ->where('deposit', '₦250,000')
            ->where('hours', [24, 48, 72])
            ->where('payWithin', 12));
});

it('shows the lot\'s bank details and only holds the car once the lot confirms the transfer', function () {
    ($this->reserve)()->assertRedirect();
    $reservation = Reservation::withoutGlobalScopes()->sole();

    // No payment through LotLink: the buyer pays the lot directly.
    expect(Payment::count())->toBe(0)
        ->and($this->payments->checkouts)->toBe([])
        ->and($reservation)->status->toBe(ReservationStatus::Pending)->reference->toStartWith('RES-')
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and($this->owner->notifications()->where('data->kind', 'reservation')->count())->toBe(1);

    $this->actingAs($this->buyer)->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->component('Deals/ReservationPay')
        ->where('account.account_number', '0123456789')
        ->where('account.account_name', 'Prime Motors Ltd')
        ->where('reservation.reference', $reservation->reference)
        ->where('reservation.deposit', '₦250,000'));

    // Asking again returns the same request.
    ($this->reserve)();
    expect(Reservation::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs($this->buyer)->post(route('reservations.sent', $reservation))->assertSessionHasNoErrors();
    expect($reservation->fresh()->buyer_paid_at)->not->toBeNull()
        ->and($this->owner->notifications()->where('data->kind', 'reservation')->count())->toBe(2);

    ($this->confirm)()->assertSessionHasNoErrors();

    expect($reservation->fresh())->status->toBe(ReservationStatus::Active)->confirmed_by->toBe($this->owner->id)
        ->expires_at->toIso8601String()->toBe('2026-10-07T09:00:00+00:00')
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved)
        ->and(Lead::withoutGlobalScopes()->sole()->stage)->toBe(LeadStage::Negotiating)
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[0]->params[3])->toContain("received your ₦250,000 deposit. It's reserved for you until Wed 7 Oct, 10:00am");

    // Bank details aren't shown once the request is answered, and other buyers can't see it.
    $this->actingAs($this->buyer)->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('account', null));
    $this->actingAs(User::factory()->create())->get(route('reservations.show', $reservation))->assertNotFound();

    // The Billing page is only for what the lot pays LotLink.
    $this->actingAs($this->owner)->get(route('dealer.billing', $this->lot))->assertInertia(fn (Assert $page) => $page->has('payments', 0));
});

it('lets one request win and tells the other buyers', function () {
    ($this->reserve)();
    $other = User::factory()->create(['phone' => '+2348035550002']);
    ($this->reserve)(24, $other);
    $this->actingAs($other)->post(route('reservations.sent', Reservation::withoutGlobalScopes()->where('customer_id', $other->id)->sole()));

    ($this->confirm)()->assertSessionHasNoErrors();

    $theirs = Reservation::withoutGlobalScopes()->where('customer_id', $other->id)->sole();
    expect($theirs)->status->toBe(ReservationStatus::Failed)->refund_due->toBeTrue()
        ->and($this->whatsapp->to('+2348035550002', 'reservation_update')[0]->params[3])->toContain('will refund it to you directly');

    ($this->confirm)($theirs)->assertSessionHasErrors('reservation');
    ($this->reserve)(48, User::factory()->create())->assertSessionHasErrors('hours');
});

it('lets the lot decline a request and record the refund', function () {
    ($this->reserve)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    $this->actingAs($this->buyer)->post(route('reservations.sent', $reservation));

    $this->actingAs($this->owner)->post(route('dealer.reservations.decline', [$this->lot, $reservation]), ['reason' => 'Car sold at the lot'])->assertSessionHasNoErrors();
    expect($reservation->fresh())->status->toBe(ReservationStatus::Failed)->refund_due->toBeTrue();

    $this->actingAs($this->owner)->get(route('dealer.offers.index', [$this->lot, 'tab' => 'reservations']))
        ->assertInertia(fn (Assert $page) => $page->where('reservations.0.status', 'refund'));

    $this->actingAs($this->owner)->post(route('dealer.reservations.refunded', [$this->lot, $reservation]))->assertSessionHasNoErrors();
    expect($reservation->fresh()->refunded_at)->not->toBeNull()
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[1]->params[3])->toContain('has refunded your ₦250,000 deposit');
    $this->actingAs($this->owner)->post(route('dealer.reservations.refunded', [$this->lot, $reservation]))->assertSessionHasErrors('reservation');
});

it('does not reserve without bank details, a deposit setting, the Pro plan, or for staff', function () {
    ($this->reserve)(48, $this->owner)->assertSessionHasErrors(['hours' => 'You work at this lot.']);
    ($this->reserve)(12)->assertSessionHasErrors('hours');

    LotBankAccount::withoutGlobalScopes()->delete();
    ($this->reserve)()->assertSessionHasErrors('hours');
    $this->actingAs($this->buyer)->get(route('reservations.create', $this->car->ulid))->assertNotFound();

    LotBankAccount::withoutGlobalScopes()->create(['lot_id' => $this->lot->id, 'bank_name' => 'Opay', 'account_number' => '8012345678', 'account_name' => 'Prime Motors']);
    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    ($this->reserve)()->assertSessionHasErrors('hours');
});

it('expires the hold, frees the car and owes the deposit back per the lot policy', function () {
    ($this->reserve)(24);
    ($this->confirm)();

    $this->travel(23)->hours();
    $this->artisan('reservations:expire');
    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Active);

    $this->travel(2)->hours();
    $this->artisan('reservations:expire');

    expect(Reservation::withoutGlobalScopes()->sole())->status->toBe(ReservationStatus::Expired)->refund_due->toBeTrue()
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and($this->payments->refunded)->toBe([])
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[1]->params[3])->toContain('Prime Motors will refund your ₦250,000 deposit');
});

it('keeps the deposit on expiry when the lot says so', function () {
    $this->lot->update(['reservation_refundable' => false]);
    ($this->reserve)(24);
    ($this->confirm)();
    $this->travel(25)->hours();
    $this->artisan('reservations:expire');

    expect(Reservation::withoutGlobalScopes()->sole()->refund_due)->toBeFalse()
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[1]->params[3])->toContain('deposit is kept');
});

it('lets requests the lot never confirmed lapse', function () {
    ($this->reserve)();
    $this->travel(11)->hours();
    $this->artisan('reservations:expire');
    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Pending);

    $this->travel(2)->hours();
    $this->artisan('reservations:expire');

    expect(Reservation::withoutGlobalScopes()->sole())->status->toBe(ReservationStatus::Failed)->end_reason->toBe('Not confirmed in time')
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available);
});

it('lets owners and managers confirm or cancel; the lot refunds directly', function () {
    ($this->reserve)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);

    $this->actingAs($sales)->post(route('dealer.reservations.confirm', [$this->lot, $reservation]))->assertForbidden();
    ($this->confirm)();

    $this->actingAs($sales)->post(route('dealer.reservations.cancel', [$this->lot, $reservation]), ['reason' => 'Sold elsewhere'])->assertForbidden();
    $this->actingAs($this->owner)->post(route('dealer.reservations.cancel', [$this->lot, $reservation]), ['reason' => 'Car failed inspection'])->assertSessionHasNoErrors();

    expect($reservation->fresh())->status->toBe(ReservationStatus::Cancelled)->end_reason->toBe('Car failed inspection')->refund_due->toBeTrue()
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and($this->payments->refunded)->toBe([]);
});

it('uses an accepted offer as the price and turns into the sale, recording the transfer', function () {
    Offer::withoutGlobalScopes()->create([
        'lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'customer_id' => $this->buyer->id, 'amount' => 1_200_000_000,
        'status' => 'accepted', 'expires_at' => now()->addDay(), 'closed_at' => now(),
    ]);
    ($this->reserve)();
    ($this->confirm)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    expect($reservation->price)->toBe(1_200_000_000);

    // Another customer can't buy it while it's held.
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'name' => 'Bola', 'phone' => '08035550999'])
        ->assertSessionHasErrors('vehicle');

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'name' => 'Chioma Okafor', 'phone' => '08035550001'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::withoutGlobalScopes()->with('payments')->sole();
    expect($order)
        ->agreed_price->toBe(1_200_000_000)
        ->reservation_id->toBe($reservation->id)
        ->total_paid->toBe(25_000_000)
        ->status->toBe(OrderStatus::DepositPaid)
        ->and($order->payments[0]->method->value)->toBe('transfer')
        ->and($order->payments[0]->reference)->toBe($reservation->reference)
        ->and($reservation->fresh())->status->toBe(ReservationStatus::Converted)->sales_order_id->toBe($order->id)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved);

    // Converted reservations don't expire or owe refunds.
    $this->travel(3)->days();
    $this->artisan('reservations:expire');
    expect($reservation->fresh()->refund_due)->toBeFalse();
});

it('keeps reservations inside their lot', function () {
    ($this->reserve)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);

    foreach (['confirm', 'decline', 'cancel', 'refunded'] as $action) {
        $this->actingAs($otherOwner)->post(route("dealer.reservations.{$action}", [$otherLot, $reservation]), ['reason' => 'x'])->assertNotFound();
    }
    $this->actingAs($otherOwner)->get(route('dealer.offers.index', [$otherLot, 'tab' => 'reservations']))->assertInertia(fn (Assert $page) => $page->has('reservations', 0));
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

it('saves the lot\'s offer and reservation settings, and needs bank details for reservations', function () {
    $this->actingAs($this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => false, 'reservation_deposit' => '₦100,000', 'reservation_refundable' => false,
    ])->assertSessionHasNoErrors();

    expect($this->lot->fresh())
        ->accepts_offers->toBeFalse()
        ->reservation_deposit->toBe(10_000_000)
        ->reservation_refundable->toBeFalse()
        ->test_drive_deposit->toBeNull();

    $this->actingAs($this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => true, 'reservation_deposit' => '500', 'reservation_refundable' => true,
    ])->assertSessionHasErrors('reservation_deposit');

    LotBankAccount::withoutGlobalScopes()->delete();
    $this->actingAs($this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => true, 'reservation_deposit' => '50000', 'reservation_refundable' => true,
    ])->assertSessionHasErrors('reservation_deposit');

    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->put(route('dealer.settings.deals', $this->lot), ['accepts_offers' => true, 'reservation_refundable' => true])->assertForbidden();
});
