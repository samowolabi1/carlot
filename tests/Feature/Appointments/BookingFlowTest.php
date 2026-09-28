<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\BookingNotice;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'whatsapp' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);
    $this->slot = CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String();
});

function book(array $overrides = [])
{
    return test()->actingAs(test()->buyer)->post(route('bookings.store'), [
        'lot' => test()->lot->slug,
        'type' => 'test_drive',
        'starts_at' => test()->slot,
        'vehicle' => test()->car->ulid,
        'whatsapp_reminders' => true,
        ...$overrides,
    ]);
}

it('shows the booking page with slots', function () {
    $this->actingAs($this->buyer)->get(route('bookings.create', ['lot' => $this->lot->slug, 'car' => $this->car->ulid]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Bookings/Book')
            ->where('car.ulid', $this->car->ulid)
            ->has('days', 14));
});

it('sends guests to sign in first', function () {
    $this->get(route('bookings.create', ['lot' => $this->lot->slug]))->assertRedirect(route('login'));
});

it('books and confirms straight away when the lot auto-confirms', function () {
    book()->assertRedirect();

    $a = Appointment::withoutGlobalScopes()->sole();
    expect($a)
        ->status->toBe(AppointmentStatus::Confirmed)
        ->vehicle_id->toBe($this->car->id)
        ->starts_at->toIso8601String()->toBe('2026-10-06T09:30:00+00:00')
        ->ends_at->toIso8601String()->toBe('2026-10-06T10:00:00+00:00');

    $toBuyer = $this->whatsapp->to('+2348035550001', 'booking_confirmed');
    expect($toBuyer)->toHaveCount(1)
        ->and($toBuyer[0]->params)->toBe(['Chioma Okafor', 'Test drive · '.$this->car->title(), 'Prime Motors', 'Tue 6 Oct, 10:30'])
        ->and($this->whatsapp->to('+2348021112233', 'dealer_booking_alert')[0]->params[0])->toBe('New booking');
});

it('waits for the lot when it confirms manually', function () {
    $this->lot->update(['booking_auto_confirm' => false]);

    book();

    expect(Appointment::withoutGlobalScopes()->sole()->status)->toBe(AppointmentStatus::Pending)
        ->and($this->whatsapp->to('+2348035550001', 'booking_pending'))->toHaveCount(1)
        ->and($this->whatsapp->to('+2348021112233', 'dealer_booking_alert')[0]->params[0])->toContain('please confirm');
});

it('uses SMS when the buyer turns WhatsApp reminders off', function () {
    book(['whatsapp_reminders' => false]);

    expect($this->whatsapp->to('+2348035550001'))->toBe([])
        ->and($this->sms->sent[0]['to'])->toBe('+2348035550001')
        ->and($this->sms->sent[0]['message'])->toContain("You're booked");
});

it('emails a calendar file when the buyer has an email address', function () {
    Notification::fake();
    $this->buyer->update(['email' => 'chioma@example.com']);

    book();

    Notification::assertSentTo($this->buyer, BookingNotice::class, function (BookingNotice $n, array $channels) {
        $mail = $n->toMail($this->buyer);

        return $channels === ['phone', 'mail'] && $mail->rawAttachments[0]['name'] === 'lotlink-visit.ics';
    });
});

it('refuses a full slot', function () {
    Appointment::factory()->count(2)->at(CarbonImmutable::parse($this->slot))->create(['lot_id' => $this->lot->id]);

    book()->assertSessionHasErrors('starts_at');
    expect(Appointment::withoutGlobalScopes()->count())->toBe(2);
});

it('refuses times that are not slots, and cars from other lots', function () {
    book(['starts_at' => CarbonImmutable::parse('2026-10-06 10:10', 'Africa/Lagos')->toIso8601String()])->assertSessionHasErrors('starts_at');
    book(['vehicle' => Vehicle::factory()->withPhoto()->available()->create()->ulid])->assertSessionHasErrors('vehicle');
});

it('stops the same buyer booking one slot twice', function () {
    book();
    book()->assertSessionHasErrors('starts_at');
});

it('opens a booking from a signed link without signing in, and cancels it', function () {
    book();
    $a = Appointment::withoutGlobalScopes()->sole();
    auth()->logout();

    $this->get(route('bookings.show', $a))->assertForbidden();

    $signed = URL::signedRoute('bookings.show', ['appointment' => $a->ulid]);
    $this->get($signed)->assertInertia(fn (Assert $page) => $page->component('Bookings/Show')->where('booking.status', 'confirmed'));

    $cancel = URL::signedRoute('bookings.cancel', ['appointment' => $a->ulid]);
    $this->post($cancel, ['reason' => 'Something came up'])->assertRedirect();

    expect($a->fresh())->status->toBe(AppointmentStatus::Cancelled)->cancel_reason->toBe('Something came up')
        ->and($this->whatsapp->to('+2348021112233', 'dealer_booking_alert')[1]->params[0])->toBe('Booking cancelled by the buyer');
});

it('keeps bookings private to their buyer', function () {
    book();
    $a = Appointment::withoutGlobalScopes()->sole();

    $this->actingAs(User::factory()->create())->get(route('bookings.show', $a))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('bookings.cancel', $a))->assertForbidden();
});

it('downloads a calendar file', function () {
    book();
    $a = Appointment::withoutGlobalScopes()->sole();

    $ics = $this->actingAs($this->buyer)->get(route('bookings.calendar', $a))
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->getContent();

    expect($ics)->toContain('BEGIN:VEVENT')
        ->toContain('DTSTART:20261006T093000Z')
        ->toContain('DTEND:20261006T100000Z')
        ->toContain('UID:'.$a->ulid.'@lotlink');
});

it('lets the buyer move a booking to another free slot', function () {
    book();
    $a = Appointment::withoutGlobalScopes()->sole();
    $new = CarbonImmutable::parse('2026-10-07 14:00', 'Africa/Lagos');

    $this->actingAs($this->buyer)->patch(route('bookings.update', $a), ['starts_at' => $new->toIso8601String()])->assertRedirect();

    expect($a->fresh()->starts_at->equalTo($new))->toBeTrue()
        ->and($this->whatsapp->to('+2348021112233', 'dealer_booking_alert')[1]->params[0])->toBe('Booking moved by the buyer');
});

it('lists the buyer\'s upcoming and past bookings', function () {
    book();
    Appointment::factory()->at(now()->subDays(3))->create(['lot_id' => $this->lot->id, 'customer_id' => $this->buyer->id, 'status' => 'completed']);

    $this->actingAs($this->buyer)->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page->has('upcoming', 1)->has('past', 1));
});
