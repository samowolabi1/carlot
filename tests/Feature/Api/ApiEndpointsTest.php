<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Actions\CreateLot;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_250_000_000, 'vin' => 'JTDBR32E720123456']);
    $this->draft = Vehicle::factory()->create(['lot_id' => $this->lot->id, 'status' => VehicleStatus::Draft]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);

    $this->otherOwner = User::factory()->staff()->create(['phone' => '+2348020000002']);
    $this->otherLot = app(CreateLot::class)->run($this->otherOwner, ['name' => 'Autoworld', 'phone' => '+2348021119999']);
    $this->otherLot->update(['status' => 'active']);
});

it('searches and shows marketplace cars with public fields only', function () {
    $this->getJson(route('api.cars.index'))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $this->car->ulid)
        ->assertJsonPath('data.0.price_value', 12_500_000)
        ->assertJsonPath('data.0.lot.slug', $this->lot->slug)
        ->assertJsonPath('meta.total', 1);

    $car = $this->getJson(route('api.cars.show', $this->car->ulid))->assertOk()->json('data');
    expect($car)->not->toHaveKeys(['id', 'lot_id', 'vin', 'costs'])
        ->and(collect($car['specs'])->firstWhere('label', 'VIN')['value'])->toBe('···3456')
        ->and($car['lot']['name'])->toBe('Prime Motors')
        ->and($car['saved'])->toBeFalse();

    $this->getJson(route('api.cars.show', $this->draft->ulid))->assertNotFound();
    $this->getJson(route('api.cars.filters'))->assertOk()->assertJsonStructure(['data' => ['makes', 'body_types', 'radii']]);
    $this->getJson(route('api.lots.show', $this->lot->slug))->assertOk()->assertJsonPath('data.name', 'Prime Motors')->assertJsonCount(1, 'cars');
    $this->getJson(route('api.lots.slots', $this->lot->slug))->assertOk()->assertJsonPath('timezone', 'Africa/Lagos');
});

it('saves cars for a signed-in buyer', function () {
    Sanctum::actingAs($this->buyer, ['app']);

    $this->putJson(route('api.saved.store', $this->car->ulid))->assertOk()->assertJson(['saved' => true]);
    $this->getJson(route('api.cars.show', $this->car->ulid))->assertJsonPath('data.saved', true);
    $this->getJson(route('api.saved.index'))->assertJsonPath('data.0.ulid', $this->car->ulid);
    $this->putJson(route('api.saved.store', $this->draft->ulid))->assertNotFound();
    $this->deleteJson(route('api.saved.destroy', $this->car->ulid))->assertJson(['saved' => false]);
    expect($this->buyer->favourites()->count())->toBe(0);
});

it('chats with a seller: the buyer starts and replies, the seller answers from the app', function () {
    Sanctum::actingAs($this->buyer, ['app']);
    $thread = $this->postJson(route('api.conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Is it still available?'])
        ->assertCreated()->assertJsonPath('data.lot.slug', $this->lot->slug)->json('data');
    $firstId = $thread['messages'][0]['id'];

    $this->postJson(route('api.conversations.reply', $thread['ulid']), ['body' => 'Can I come Saturday?'])->assertCreated();
    $this->getJson(route('api.conversations.index'))->assertJsonPath('data.0.ulid', $thread['ulid'])->assertJsonPath('data.0.last', 'Can I come Saturday?');
    $this->getJson(route('api.conversations.show', ['conversation' => $thread['ulid'], 'after' => $firstId]))
        ->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.body', 'Can I come Saturday?');

    // The seller's side.
    $lead = Lead::withoutGlobalScopes()->sole();
    Sanctum::actingAs($this->owner, ['app']);
    $this->getJson(route('api.dealer.leads.index', $this->lot))->assertOk()->assertJsonPath('data.0.ulid', $lead->ulid)->assertJsonPath('data.0.unread', 2);
    $this->postJson(route('api.dealer.leads.messages', [$this->lot, $lead]), ['body' => 'Yes, come by 10am'])->assertCreated()
        ->assertJsonPath('data.messages.2.body', 'Yes, come by 10am')->assertJsonPath('data.messages.2.side', 'lot');
    $this->patchJson(route('api.dealer.leads.update', [$this->lot, $lead]), ['stage' => 'negotiating'])->assertOk()->assertJsonPath('data.stage', 'negotiating');
    // The buyer's number stays masked until they engage (the same rule as the web).
    expect($this->getJson(route('api.dealer.leads.show', [$this->lot, $lead]))->json('data.phone'))->not->toBe('+2348035550001');

    // Nobody else reads the buyer's thread.
    Sanctum::actingAs($this->otherOwner, ['app']);
    $this->getJson(route('api.conversations.show', $thread['ulid']))->assertForbidden();
});

it('keeps lot staff to their own lots (tenancy)', function () {
    Sanctum::actingAs($this->buyer, ['app']);
    $this->postJson(route('api.conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Hi']);
    $lead = Lead::withoutGlobalScopes()->sole();

    // A member of another lot: no access to this seller, and this seller's lead isn't found under theirs.
    Sanctum::actingAs($this->otherOwner, ['app']);
    $this->getJson(route('api.dealer.leads.index', $this->lot))->assertForbidden();
    $this->getJson(route('api.dealer.vehicles.index', $this->lot))->assertForbidden();
    $this->getJson(route('api.dealer.leads.show', [$this->otherLot, $lead]))->assertNotFound();
    $this->postJson(route('api.dealer.leads.messages', [$this->otherLot, $lead]), ['body' => 'Hi'])->assertNotFound();
    $this->getJson(route('api.dealer.leads.index', $this->otherLot))->assertOk()->assertJsonCount(0, 'data');

    // A buyer isn't staff anywhere.
    Sanctum::actingAs($this->buyer, ['app']);
    $this->getJson(route('api.dealer.leads.index', $this->lot))->assertForbidden();
});

it('lists stock and the calendar for staff, without costs', function () {
    Sanctum::actingAs($this->owner, ['app']);

    $vehicles = $this->getJson(route('api.dealer.vehicles.index', $this->lot))->assertOk()->assertJsonPath('meta.total', 2)->json('data');
    expect(collect($vehicles)->pluck('ulid')->all())->toContain($this->car->ulid, $this->draft->ulid)
        ->and($vehicles[0])->not->toHaveKeys(['id', 'lot_id', 'cost', 'costs', 'purchase_price', 'profit']);
    $this->getJson(route('api.dealer.vehicles.index', [$this->lot, 'status' => 'draft']))->assertJsonPath('meta.total', 1);
    $this->getJson(route('api.dealer.appointments.index', $this->lot))->assertOk()->assertJsonPath('data', []);
});

it('books, lists, moves and cancels a visit', function () {
    Sanctum::actingAs($this->buyer, ['app']);
    $slot = CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String();

    $booking = $this->postJson(route('api.bookings.store'), ['lot' => $this->lot->slug, 'type' => 'test_drive', 'starts_at' => $slot, 'vehicle' => $this->car->ulid])
        ->assertCreated()->assertJsonPath('data.lot.slug', $this->lot->slug)->assertJsonPath('data.car.ulid', $this->car->ulid)->json('data');
    $this->getJson(route('api.bookings.index'))->assertJsonPath('data.0.ulid', $booking['ulid']);

    $later = CarbonImmutable::parse('2026-10-06 14:00', 'Africa/Lagos')->utc()->toIso8601String();
    $this->patchJson(route('api.bookings.update', $booking['ulid']), ['starts_at' => $later])->assertOk()->assertJsonPath('data.starts_at', CarbonImmutable::parse($later)->toIso8601String());

    Sanctum::actingAs($this->owner, ['app']);
    $this->getJson(route('api.dealer.appointments.index', $this->lot))->assertJsonPath('data.0.ulid', $booking['ulid'])->assertJsonPath('data.0.customer', 'Chioma Okafor');
    // Only the buyer can see or cancel it through the buyer endpoints.
    $this->getJson(route('api.bookings.show', $booking['ulid']))->assertNotFound();
    $this->postJson(route('api.bookings.cancel', $booking['ulid']))->assertNotFound();

    Sanctum::actingAs($this->buyer, ['app']);
    $this->postJson(route('api.bookings.cancel', $booking['ulid']), ['reason' => 'Plans changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(Appointment::withoutGlobalScopes()->sole()->status)->toBe(AppointmentStatus::Cancelled);
});

it('serves the notification centre', function () {
    Sanctum::actingAs($this->owner, ['app']);
    Sanctum::actingAs($this->buyer, ['app']);
    $this->postJson(route('api.conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Hi']);

    Sanctum::actingAs($this->owner, ['app']);
    $this->getJson(route('api.notifications.index'))->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('data.0.kind', 'lead');
    $this->postJson(route('api.notifications.read'))->assertJson(['unread' => 0]);
    expect($this->owner->unreadNotifications()->count())->toBe(0)
        ->and(Conversation::count())->toBe(1);
});
