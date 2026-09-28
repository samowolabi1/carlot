<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
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
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id'), 'reservation_deposit' => 25_000_000]); // ₦250,000
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_250_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);

    $this->reserve = function (int $hours = 48, ?User $as = null) {
        return $this->actingAs($as ?? $this->buyer)->post(route('reservations.store', $this->car->ulid), ['hours' => $hours, 'channel' => 'transfer']);
    };
    $this->pay = fn () => $this->actingAs($this->buyer)->get(route('reservations.callback', ['reference' => $this->payments->lastReference()]));
});

it('shows the reserve page with the deposit and hold times', function () {
    $this->actingAs($this->buyer)->get(route('reservations.create', $this->car->ulid))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deals/Reserve')
            ->where('deposit', '₦250,000')
            ->where('hours', [24, 48, 72])
            ->where('until.48', 'Wed 7 Oct, 10:00am'));
});

it('sends the buyer to pay and only holds the car once the payment is verified', function () {
    ($this->reserve)()->assertRedirect('https://checkout.paystack.test/'.$this->payments->lastReference());

    $reservation = Reservation::withoutGlobalScopes()->sole();
    $payment = Payment::sole();
    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and($payment)->purpose->toBe(PaymentPurpose::Reservation)->amount->toBe(25_000_000)->user_id->toBe($this->buyer->id)
        ->and($payment->meta['channels'])->toBe(['bank_transfer'])
        ->and($this->payments->checkouts[0]['email'])->toStartWith('buyer-'.$this->buyer->ulid.'@');

    ($this->pay)()->assertSessionHas('success', 'Paid. The car is reserved for you.');

    expect($reservation->fresh())->status->toBe(ReservationStatus::Active)->expires_at->toIso8601String()->toBe('2026-10-07T09:00:00+00:00')
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved)
        ->and(Lead::withoutGlobalScopes()->sole()->stage)->toBe(LeadStage::Negotiating)
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[0]->params[3])->toContain("It's reserved for you until Wed 7 Oct, 10:00am")
        ->and($this->owner->notifications()->where('data->kind', 'reservation')->count())->toBe(1);

    // The Billing page is only for what the lot pays LotLink.
    $this->actingAs($this->owner)->get(route('dealer.billing', $this->lot))->assertInertia(fn (Assert $page) => $page->has('payments', 0));
});

it('refunds when the car was taken while the buyer paid', function () {
    ($this->reserve)();
    $first = $this->payments->lastReference();
    $other = User::factory()->create(['phone' => '+2348035550002']);
    ($this->reserve)(24, $other);
    $theirs = $this->payments->lastReference();
    $this->actingAs($this->buyer)->get(route('reservations.callback', ['reference' => $first]));

    $this->actingAs($other)->get(route('reservations.callback', ['reference' => $theirs]))->assertSessionHas('error');

    expect(Reservation::withoutGlobalScopes()->where('customer_id', $other->id)->sole()->status)->toBe(ReservationStatus::Failed)
        ->and(Payment::where('reference', $theirs)->sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->payments->refunded)->toBe([$theirs]);

    ($this->reserve)(48, User::factory()->create())->assertSessionHasErrors('hours');
});

it('does not reserve without a deposit setting, the Pro plan, or for staff', function () {
    ($this->reserve)(48, $this->owner)->assertSessionHasErrors(['hours' => 'You work at this lot.']);
    ($this->reserve)(12)->assertSessionHasErrors('hours');

    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    ($this->reserve)()->assertSessionHasErrors('hours');
    $this->actingAs($this->buyer)->get(route('reservations.create', $this->car->ulid))->assertNotFound();
});

it('expires the hold, frees the car and refunds per the lot policy', function () {
    ($this->reserve)(24);
    ($this->pay)();

    $this->travel(23)->hours();
    $this->artisan('reservations:expire');
    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Active);

    $this->travel(2)->hours();
    $this->artisan('reservations:expire');

    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Expired)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[1]->params[3])->toContain('is being refunded');
});

it('keeps the deposit on expiry when the lot says so', function () {
    $this->lot->update(['reservation_refundable' => false]);
    ($this->reserve)(24);
    ($this->pay)();
    $this->travel(25)->hours();
    $this->artisan('reservations:expire');

    expect(Payment::sole()->status)->toBe(PaymentStatus::Success)
        ->and($this->payments->refunded)->toBe([])
        ->and($this->whatsapp->to('+2348035550001', 'reservation_update')[1]->params[3])->toContain('deposit is kept');
});

it('closes checkouts abandoned for an hour', function () {
    ($this->reserve)();
    $this->travel(61)->minutes();
    $this->artisan('reservations:expire');

    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Failed);
});

it('lets owners and managers cancel with a refund', function () {
    ($this->reserve)();
    ($this->pay)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);

    $this->actingAs($sales)->post(route('dealer.reservations.cancel', [$this->lot, $reservation]), ['reason' => 'Sold elsewhere'])->assertForbidden();
    $this->actingAs($this->owner)->post(route('dealer.reservations.cancel', [$this->lot, $reservation]), ['reason' => 'Car failed inspection'])->assertSessionHasNoErrors();

    expect($reservation->fresh())->status->toBe(ReservationStatus::Cancelled)->end_reason->toBe('Car failed inspection')
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Available)
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Refunded);
});

it('uses an accepted offer as the price and turns into the sale', function () {
    Offer::withoutGlobalScopes()->create([
        'lot_id' => $this->lot->id, 'vehicle_id' => $this->car->id, 'customer_id' => $this->buyer->id, 'amount' => 1_200_000_000,
        'status' => 'accepted', 'expires_at' => now()->addDay(), 'closed_at' => now(),
    ]);
    ($this->reserve)();
    ($this->pay)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    expect($reservation->price)->toBe(1_200_000_000);

    // Another customer can't buy it while it's held.
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'name' => 'Bola', 'phone' => '08035550999'])
        ->assertSessionHasErrors('vehicle');

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $this->car->ulid, 'name' => 'Chioma Okafor', 'phone' => '08035550001'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::withoutGlobalScopes()->sole();
    expect($order)
        ->agreed_price->toBe(1_200_000_000)
        ->reservation_id->toBe($reservation->id)
        ->total_paid->toBe(25_000_000)
        ->status->toBe(OrderStatus::DepositPaid)
        ->and($reservation->fresh())->status->toBe(ReservationStatus::Converted)->sales_order_id->toBe($order->id)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved);

    // Converted reservations don't expire or refund.
    $this->travel(3)->days();
    $this->artisan('reservations:expire');
    expect($this->payments->refunded)->toBe([]);
});

it('keeps reservations inside their lot', function () {
    ($this->reserve)();
    ($this->pay)();
    $reservation = Reservation::withoutGlobalScopes()->sole();
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);

    $this->actingAs($otherOwner)->post(route('dealer.reservations.cancel', [$otherLot, $reservation]), ['reason' => 'x'])->assertNotFound();
    $this->actingAs($otherOwner)->get(route('dealer.offers.index', [$otherLot, 'tab' => 'reservations']))->assertInertia(fn (Assert $page) => $page->has('reservations', 0));
});

it('activates a reservation from the Paystack webhook if the buyer never comes back', function () {
    ($this->reserve)();
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $this->payments->lastReference(), 'amount' => 25_000_000]]);

    $this->call('POST', route('webhooks.paystack'), [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, FakePaymentGateway::SECRET),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(Reservation::withoutGlobalScopes()->sole()->status)->toBe(ReservationStatus::Active)
        ->and($this->car->fresh()->status)->toBe(VehicleStatus::Reserved);
});

it('saves the lot\'s offer and deposit settings', function () {
    $this->actingAs($this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => false, 'reservation_deposit' => '₦100,000', 'reservation_refundable' => false, 'test_drive_deposit' => '',
    ])->assertSessionHasNoErrors();

    expect($this->lot->fresh())
        ->accepts_offers->toBeFalse()
        ->reservation_deposit->toBe(10_000_000)
        ->reservation_refundable->toBeFalse()
        ->test_drive_deposit->toBeNull();

    $this->actingAs($this->owner)->put(route('dealer.settings.deals', $this->lot), [
        'accepts_offers' => true, 'reservation_deposit' => '500', 'reservation_refundable' => true,
    ])->assertSessionHasErrors('reservation_deposit');

    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->put(route('dealer.settings.deals', $this->lot), ['accepts_offers' => true, 'reservation_refundable' => true])->assertForbidden();
});
