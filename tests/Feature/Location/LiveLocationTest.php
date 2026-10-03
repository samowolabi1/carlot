<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'latitude' => 6.6018, 'longitude' => 3.3515]);
    $this->lot->update(['status' => 'active']);
    $this->buyer = User::factory()->create(['name' => 'Kemi Ade', 'phone' => '+2348035550001']);

    $this->actingAs($this->buyer)->post(route('bookings.store'), [
        'lot' => $this->lot->slug, 'type' => 'viewing',
        'starts_at' => CarbonImmutable::parse('2026-10-05 11:30', 'Africa/Lagos')->utc()->toIso8601String(),
    ])->assertSessionHasNoErrors();
    $this->appointment = Appointment::withoutGlobalScopes()->sole();
    $this->start = fn (?User $as = null, int $minutes = 30) => $this->actingAs($as ?? $this->buyer)->post(route('location.start', $this->appointment), ['minutes' => $minutes]);
});

it('lets the buyer share for a while, and the seller follow along', function () {
    ($this->start)()->assertRedirect();
    $session = LocationSession::withoutGlobalScopes()->sole();

    expect($session)->sharer_side->toBe('customer')->expires_at->toIso8601String()->toBe('2026-10-05T08:30:00+00:00')
        ->and($this->owner->notifications()->where('data->kind', 'location')->sole()->data['text'])->toContain('Kemi A. is sharing live location');

    $this->actingAs($this->buyer)->postJson(route('location.position', $session), ['lat' => 6.5901, 'lng' => 3.3402, 'accuracy' => 12.4])->assertOk();

    $this->actingAs($this->owner)->get(route('location.show', $session))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Location/Live')
            ->where('role', 'viewer')
            ->where('session.point.lat', 6.5901)
            ->where('call.phone', '+2348035550001'));
    $this->actingAs($this->owner)->getJson(route('location.point', $session))->assertJsonPath('point.accuracy', 12);

    $this->actingAs($this->buyer)->get(route('bookings.show', $this->appointment))
        ->assertInertia(fn (Assert $page) => $page->where('location.live.0.mine', true));
});

it('keeps the point to the two sides of the booking', function () {
    ($this->start)();
    $session = LocationSession::withoutGlobalScopes()->sole();
    $this->actingAs($this->buyer)->postJson(route('location.position', $session), ['lat' => 6.59, 'lng' => 3.34]);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('location.show', $session))->assertForbidden();
    $this->actingAs($stranger)->getJson(route('location.point', $session))->assertForbidden();
    $this->actingAs($stranger)->postJson(route('location.position', $session), ['lat' => 1, 'lng' => 1])->assertForbidden();
    $this->actingAs($this->owner)->postJson(route('location.position', $session), ['lat' => 1, 'lng' => 1])->assertForbidden();

    $otherOwner = User::factory()->staff()->create();
    app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($otherOwner)->get(route('location.show', $session))->assertForbidden();
    $this->actingAs($otherOwner)->post(route('location.start', $this->appointment), ['minutes' => 30])->assertForbidden();
});

it('only shares close to the visit, for the allowed lengths', function () {
    ($this->start)(null, 45)->assertSessionHasErrors('minutes');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00', 'UTC')); // more than 3 hours before
    ($this->start)()->assertSessionHasErrors('minutes');
});

it('forgets the point when sharing ends: by hand, on expiry, or when the visit ends', function () {
    ($this->start)(null, 15);
    $session = LocationSession::withoutGlobalScopes()->sole();
    $this->actingAs($this->buyer)->postJson(route('location.position', $session), ['lat' => 6.59, 'lng' => 3.34]);

    $this->actingAs($this->buyer)->post(route('location.stop', $session))->assertRedirect(route('bookings.show', $this->appointment));
    expect($session->fresh())->ended_at->not->toBeNull()->last_latitude->toBeNull();
    $this->actingAs($this->buyer)->postJson(route('location.position', $session), ['lat' => 6.59, 'lng' => 3.34])->assertUnprocessable();

    ($this->start)(null, 15);
    $second = LocationSession::withoutGlobalScopes()->whereNull('ended_at')->sole();
    $this->actingAs($this->buyer)->postJson(route('location.position', $second), ['lat' => 6.59, 'lng' => 3.34]);
    $this->travel(16)->minutes();
    $this->artisan('location:end-expired');
    expect($second->fresh())->ended_at->not->toBeNull()->last_longitude->toBeNull();

    ($this->start)(null, 60);
    $third = LocationSession::withoutGlobalScopes()->whereNull('ended_at')->sole();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:25', 'UTC'));
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $this->appointment]), ['action' => 'check_in']);
    $this->actingAs($this->owner)->patch(route('dealer.appointments.update', [$this->lot, $this->appointment]), ['action' => 'complete'])->assertSessionHasNoErrors();
    expect($third->fresh()->ended_at)->not->toBeNull();
});

it('lets a team member share their location with the buyer', function () {
    $sales = User::factory()->staff()->create(['name' => 'Dayo Ola']);
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);

    ($this->start)($sales)->assertRedirect();
    $session = LocationSession::withoutGlobalScopes()->sole();

    expect($session->sharer_side)->toBe('lot')
        ->and($this->whatsapp->to('+2348035550001', 'appointment_update')[0]->params[2])->toBe('Prime Motors is sharing their live location');

    $this->actingAs($this->buyer)->get(route('location.show', $session))->assertInertia(fn (Assert $page) => $page->where('role', 'viewer')->where('call.phone', '+2348021112233'));
    // Another team member isn't the other side of a seller-shared session.
    $this->actingAs($this->owner)->get(route('location.show', $session))->assertForbidden();
});

it('authorises the private location channel for the two sides only', function () {
    ($this->start)();
    $session = LocationSession::withoutGlobalScopes()->sole();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'key', 'broadcasting.connections.reverb.secret' => 'secret', 'broadcasting.connections.reverb.app_id' => 'app']);
    app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
    require base_path('routes/channels.php');

    $auth = fn (User $u) => $this->actingAs($u)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-location-session.{$session->ulid}"]);
    $auth($this->owner)->assertOk();
    $auth($this->buyer)->assertOk();
    $auth(User::factory()->create())->assertForbidden();
});
