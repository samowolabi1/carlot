<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->buyer = User::factory()->create(['phone' => '+2348035550001']);
    $this->booking = fn (array $attrs = [], string $at = '2026-10-06 10:00') => Appointment::factory()
        ->at(CarbonImmutable::parse($at, 'Africa/Lagos'))
        ->create(['lot_id' => $this->lot->id, 'customer_id' => $this->buyer->id, ...$attrs]);
});

it('shows the week, today\'s visits and requests to confirm', function () {
    ($this->booking)([], '2026-10-05 11:00');
    ($this->booking)(['status' => 'pending', 'confirmed_at' => null], '2026-10-07 15:00');
    ($this->booking)(['status' => 'cancelled'], '2026-10-06 12:00');

    $this->actingAs($this->owner)->get(route('dealer.calendar', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Calendar')
            ->where('week.start', '2026-10-05')
            ->has('appointments', 2)
            ->has('today.items', 1)
            ->has('pending', 1)
            ->where('appointments.0.phone', '+2348035550001'));
});

it('stretches the grid over visits booked outside opening hours', function () {
    $this->actingAs($this->owner)->get(route('dealer.calendar', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('week.first_hour', 8)->where('week.last_hour', 18));

    ($this->booking)([], '2026-10-06 06:00');
    ($this->booking)([], '2026-10-08 19:30');

    $this->actingAs($this->owner)->get(route('dealer.calendar', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('week.first_hour', 6)->where('week.last_hour', 20)); // 19:30 + 30 min
});

it('confirms a request and tells the buyer', function () {
    $a = ($this->booking)(['status' => 'pending', 'confirmed_at' => null]);

    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['action' => 'confirm'])->assertRedirect();

    expect($a->fresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($this->whatsapp->to('+2348035550001', 'booking_confirmed'))->toHaveCount(1);
});

it('checks buyers in, completes visits and marks no-shows at the right times', function () {
    $later = ($this->booking)([], '2026-10-06 10:00');
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $later]), ['action' => 'check_in'])->assertSessionHasErrors('action');

    $soon = ($this->booking)([], '2026-10-05 10:00');
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $soon]), ['action' => 'no_show'])->assertSessionHasErrors('action');
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $soon]), ['action' => 'check_in'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $soon]), ['action' => 'complete'])->assertSessionHasNoErrors();
    expect($soon->fresh()->status)->toBe(AppointmentStatus::Completed);

    $this->travel(2)->hours();
    $missed = ($this->booking)([], '2026-10-05 10:00');
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $missed]), ['action' => 'no_show'])->assertSessionHasNoErrors();
    expect($missed->fresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('moves a booking dropped on the grid, in lot time, and tells the buyer', function () {
    $a = ($this->booking)();

    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['date' => '2026-10-08', 'time' => '15:30'])->assertSessionHasNoErrors();

    expect($a->fresh()->starts_at->toIso8601String())->toBe('2026-10-08T14:30:00+00:00')
        ->and($this->whatsapp->to('+2348035550001', 'appointment_update')[0]->params[2])->toBe('moved to Thu 8 Oct, 15:30');
});

it('rejects a drop onto a closed or off-grid time', function () {
    $a = ($this->booking)();

    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['date' => '2026-10-11', 'time' => '10:00'])->assertSessionHasErrors('starts_at');
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['date' => '2026-10-06', 'time' => '10:15'])->assertSessionHasErrors('starts_at');
});

it('cancels with a reason the buyer sees', function () {
    $a = ($this->booking)();

    $this->actingAs($this->owner)->post(route('dealer.appointments.cancel', [$this->lot, $a]), ['reason' => 'The car has been sold']);

    expect($a->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($this->whatsapp->to('+2348035550001', 'appointment_update')[0]->params[2])->toBe('cancelled: The car has been sold');
});

it('lets managers assign reps, and keeps sales reps to their own bookings', function () {
    $sales = User::factory()->staff()->create();
    $other = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => 'sales', 'accepted_at' => now()]);
    $this->lot->members()->attach($other, ['role' => 'sales', 'accepted_at' => now()]);
    $a = ($this->booking)(['status' => 'pending', 'confirmed_at' => null]);

    $this->actingAs($sales)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['staff' => $sales->ulid])->assertForbidden();
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['staff' => $other->ulid])->assertRedirect();
    expect($a->fresh()->staff_id)->toBe($other->id);

    $this->actingAs($sales)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['action' => 'confirm'])->assertForbidden();
    $this->actingAs($other)->patch(route('dealer.appointments.update', [$this->lot, $a]), ['action' => 'confirm'])->assertSessionHasNoErrors();
});

it('keeps each seller\'s bookings to itself', function () {
    $otherLot = Lot::factory()->active()->create();
    $theirs = Appointment::factory()->create(['lot_id' => $otherLot->id]);

    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $theirs]), ['action' => 'confirm'])->assertNotFound();
    $this->actingAs($this->owner)->getJson(route('dealer.appointments.slots', [$this->lot, $theirs]))->assertNotFound();
    $this->actingAs($this->owner)->get(route('dealer.calendar', $otherLot))->assertForbidden();
});

it('saves booking rules and closures', function () {
    $this->actingAs($this->owner)->put(route('dealer.settings.booking', $this->lot), ['booking_auto_confirm' => false, 'booking_min_notice_minutes' => 240])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('dealer.settings.closures.store', $this->lot), ['date' => '2026-10-01'])->assertSessionHasErrors('date');
    $this->actingAs($this->owner)->post(route('dealer.settings.closures.store', $this->lot), ['date' => '2026-10-10', 'reason' => 'Stock-taking'])->assertSessionHasNoErrors();

    expect($this->lot->fresh())->booking_auto_confirm->toBeFalse()->booking_min_notice_minutes->toBe(240)
        ->and($this->lot->closures()->withoutGlobalScopes()->count())->toBe(1);
});
