<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'whatsapp' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id'), 'test_drive_deposit' => 500_000]); // ₦5,000
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);
    $this->slot = CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String();

    $this->book = fn (string $type = 'test_drive') => $this->actingAs($this->buyer)->post(route('bookings.store'), [
        'lot' => $this->lot->slug, 'type' => $type, 'starts_at' => $this->slot, 'vehicle' => $this->car->ulid, 'whatsapp_reminders' => true,
    ]);
    $this->pay = fn () => $this->actingAs($this->buyer)->get(route('bookings.deposit.callback', ['reference' => $this->payments->lastReference()]));
});

it('asks for the deposit before a test drive stands', function () {
    $this->actingAs($this->buyer)->get(route('bookings.create', ['lot' => $this->lot->slug, 'car' => $this->car->ulid]))
        ->assertInertia(fn (Assert $page) => $page->where('deposit', '₦5,000'));

    ($this->book)()->assertRedirect('https://checkout.paystack.test/'.$this->payments->lastReference());

    $a = Appointment::withoutGlobalScopes()->sole();
    expect($a->status)->toBe(AppointmentStatus::AwaitingDeposit)
        ->and(Payment::sole())->purpose->toBe(PaymentPurpose::Deposit)->amount->toBe(500_000)
        // Nothing is announced until it's paid.
        ->and($this->whatsapp->to('+2348035550001'))->toBe([])
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);

    // The slot is held meanwhile.
    ($this->book)()->assertSessionHasErrors('starts_at');

    ($this->pay)()->assertRedirect(route('bookings.show', $a));

    expect($a->fresh())->status->toBe(AppointmentStatus::Confirmed)->deposit_payment_id->toBe(Payment::sole()->id)
        ->and($this->whatsapp->to('+2348035550001', 'booking_confirmed'))->toHaveCount(1)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs($this->buyer)->get(route('bookings.show', $a))->assertInertia(fn (Assert $page) => $page->where('booking.deposit.state', 'paid'));
});

it('does not ask for a deposit for viewings or off the Pro plan', function () {
    ($this->book)('viewing')->assertRedirect();
    expect(Appointment::withoutGlobalScopes()->sole()->status)->toBe(AppointmentStatus::Confirmed);

    Appointment::query()->delete();
    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    ($this->book)();
    expect(Appointment::withoutGlobalScopes()->sole()->status)->toBe(AppointmentStatus::Confirmed)
        ->and(Payment::count())->toBe(0);
});

it('releases the slot when the deposit isn\'t paid in 30 minutes, and refunds a late payment', function () {
    ($this->book)();
    $this->travel(31)->minutes();
    $this->artisan('appointments:release-unpaid');

    $a = Appointment::withoutGlobalScopes()->sole();
    expect($a->status)->toBe(AppointmentStatus::Cancelled)->and($a->cancel_reason)->toBe('Deposit not paid');

    ($this->pay)()->assertSessionHas('error');
    expect(Payment::sole()->status)->toBe(PaymentStatus::Refunded);
});

it('refunds the deposit when the buyer arrives', function () {
    ($this->book)();
    ($this->pay)();
    $a = Appointment::withoutGlobalScopes()->sole();

    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:25', 'UTC'));
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['action' => 'check_in'])->assertSessionHasNoErrors();

    expect(Payment::sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->whatsapp->to('+2348035550001', 'appointment_update')[0]->params[2])->toBe('your ₦5,000 deposit has been refunded');
});

it('refunds when the buyer cancels, and keeps it for a no-show', function () {
    ($this->book)();
    ($this->pay)();
    $a = Appointment::withoutGlobalScopes()->sole();
    $this->actingAs($this->buyer)->post(route('bookings.cancel', $a), ['reason' => 'Change of plans'])->assertRedirect();
    expect(Payment::sole()->status)->toBe(PaymentStatus::Refunded);

    $this->slot = CarbonImmutable::parse('2026-10-06 11:30', 'Africa/Lagos')->utc()->toIso8601String();
    ($this->book)();
    ($this->pay)();
    $second = Appointment::withoutGlobalScopes()->latest('id')->first();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 11:00', 'UTC'));
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $second]), ['action' => 'no_show'])->assertSessionHasNoErrors();

    expect(Payment::where('id', $second->deposit_payment_id)->sole()->status)->toBe(PaymentStatus::Success);
});
