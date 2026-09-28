<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));
    $this->owner = User::factory()->create(['phone' => '+2348020000001']);
    $this->lot = Lot::factory()->active()->create(['owner_id' => $this->owner->id, 'whatsapp' => '+2348021112233']);
    $this->buyer = User::factory()->create(['phone' => '+2348035550001']);
});

function appt(string $startsUtc, array $attrs = [], string $createdUtc = '2026-10-01 08:00'): Appointment
{
    $a = Appointment::factory()->at(CarbonImmutable::parse($startsUtc, 'UTC'))->create(['lot_id' => test()->lot->id, 'customer_id' => test()->buyer->id, ...$attrs]);
    $a->forceFill(['created_at' => CarbonImmutable::parse($createdUtc, 'UTC')])->save();

    return $a->fresh();
}

it('sends the 24-hour reminder, then the 2-hour one', function () {
    $a = appt('2026-10-06 07:00'); // 23 hours away, booked days ago

    $this->artisan('appointments:remind')->assertSuccessful();
    expect($this->whatsapp->to('+2348035550001', 'appointment_reminder'))->toHaveCount(1)
        ->and($a->fresh()->reminded_24h_at)->not->toBeNull();

    $this->artisan('appointments:remind');
    expect($this->whatsapp->to('+2348035550001', 'appointment_reminder'))->toHaveCount(1); // not twice

    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:30', 'UTC'));
    $this->artisan('appointments:remind');
    $reminders = $this->whatsapp->to('+2348035550001', 'appointment_reminder');
    expect($reminders)->toHaveCount(2)->and($reminders[1]->params[2])->toBe('today at 08:00'); // lot time (Lagos)
});

it('skips the 24-hour reminder for bookings made less than a day ahead', function () {
    appt('2026-10-06 02:00', [], createdUtc: '2026-10-05 07:30');

    $this->artisan('appointments:remind');

    expect($this->whatsapp->to('+2348035550001', 'appointment_reminder'))->toBe([]);
});

it('does not remind about pending or cancelled bookings', function () {
    appt('2026-10-05 09:00', ['status' => 'pending', 'confirmed_at' => null]);
    appt('2026-10-05 09:00', ['status' => 'cancelled']);

    $this->artisan('appointments:remind');

    expect($this->whatsapp->sent)->toBe([]);
});

it('marks no-shows, lapses stale requests and completes checked-in visits', function () {
    $missed = appt('2026-10-05 07:00');
    $tooRecent = appt('2026-10-05 07:40');
    $stale = appt('2026-10-05 07:00', ['status' => 'pending', 'confirmed_at' => null]);
    $visited = appt('2026-10-05 06:00');
    $visited->forceFill(['checked_in_at' => now()->subHours(2)])->save();

    $this->artisan('appointments:mark-no-shows')->assertSuccessful();

    expect($missed->fresh()->status)->toBe(AppointmentStatus::NoShow)
        ->and($tooRecent->fresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($stale->fresh()->status)->toBe(AppointmentStatus::Cancelled)
        ->and($visited->fresh()->status)->toBe(AppointmentStatus::Completed);
});

it('escalates requests left unconfirmed for 4 hours to the owner, once', function () {
    appt('2026-10-06 09:00', ['status' => 'pending', 'confirmed_at' => null], createdUtc: '2026-10-05 03:00');
    appt('2026-10-06 09:30', ['status' => 'pending', 'confirmed_at' => null], createdUtc: '2026-10-05 07:00');

    $this->artisan('appointments:escalate-pending');
    $this->artisan('appointments:escalate-pending');

    $alerts = $this->whatsapp->to('+2348020000001', 'dealer_booking_alert');
    expect($alerts)->toHaveCount(1)->and($alerts[0]->params[0])->toBe('Still waiting for you to confirm');
});
