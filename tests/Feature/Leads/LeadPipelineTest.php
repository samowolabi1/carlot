<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\LotMember;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC')); // Mon 09:00 Lagos
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);
    $this->sales = User::factory()->staff()->create(['name' => 'Tunde Bello', 'phone' => '+2348020000002']);
    LotMember::create(['lot_id' => $this->lot->id, 'user_id' => $this->sales->id, 'role' => LotRole::Sales, 'accepted_at' => now()]);

    $this->capture = fn (LeadSource $source = LeadSource::Chat, ?Vehicle $car = null, ?User $buyer = null) => app(CaptureLead::class)
        ->run($this->lot, $buyer ?? $this->buyer, $source, $car ?? $this->car);
});

it('de-duplicates a buyer\'s enquiries about one car within 30 days', function () {
    $lead = ($this->capture)();
    expect(($this->capture)(LeadSource::WhatsApp)->id)->toBe($lead->id);

    // A different car is a different lead.
    expect(($this->capture)(LeadSource::Chat, Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]))->id)->not->toBe($lead->id);

    // After 30 quiet days, or once closed, a new enquiry starts a new lead.
    $this->travel(31)->days();
    expect(($this->capture)()->id)->not->toBe($lead->id)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(3)
        ->and($this->owner->notifications()->count())->toBe(3)
        ->and($this->sales->notifications()->count())->toBe(3);
});

it('turns a booking into a test-drive lead and posts it into the chat', function () {
    $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Can I test drive it?']);

    $this->actingAs($this->buyer)->post(route('bookings.store'), [
        'lot' => $this->lot->slug,
        'type' => 'test_drive',
        'starts_at' => CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String(),
        'vehicle' => $this->car->ulid,
        'whatsapp_reminders' => true,
    ])->assertSessionHasNoErrors();

    $lead = Lead::withoutGlobalScopes()->sole();
    $system = Conversation::sole()->messages()->where('side', Message::SYSTEM)->sole();
    expect($lead->stage)->toBe(LeadStage::TestDrive)
        ->and($system->body)->toStartWith('Test drive · ')->toContain('booked')
        ->and($system->body)->toContain('Tue 6 Oct');
});

it('shows the board by stage with search and filters', function () {
    ($this->capture)();
    $other = User::factory()->create(['name' => 'Bola Ade', 'phone' => '+2348035550002']);
    ($this->capture)(LeadSource::Call, null, $other)->forceFill(['stage' => LeadStage::Negotiating, 'assigned_to' => $this->sales->id])->save();

    $this->actingAs($this->owner)->get(route('dealer.leads.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Leads/Board')
            ->where('columns.0.stage', 'new')
            ->has('columns.0.leads', 1)
            ->where('columns.0.leads.0.name', 'Chioma O.')
            ->has('columns.3.leads', 1));

    $this->actingAs($this->owner)->get(route('dealer.leads.index', [$this->lot, 'q' => 'bola']))
        ->assertInertia(fn (Assert $page) => $page->has('columns.0.leads', 0)->has('columns.3.leads', 1));

    $this->actingAs($this->sales)->get(route('dealer.leads.index', [$this->lot, 'who' => 'mine']))
        ->assertInertia(fn (Assert $page) => $page->has('columns.0.leads', 0)->has('columns.3.leads', 1));
});

it('moves leads between stages, asking why a lead was lost', function () {
    $lead = ($this->capture)();

    $this->actingAs($this->sales)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['stage' => 'negotiating'])->assertSessionHasNoErrors();
    expect($lead->fresh()->stage)->toBe(LeadStage::Negotiating)
        ->and(AuditLog::where('action', 'lead.updated')->count())->toBe(1);

    $this->actingAs($this->sales)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['stage' => 'lost'])->assertSessionHasErrors('lost_reason');
    $this->actingAs($this->sales)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['stage' => 'lost', 'lost_reason' => 'Bought elsewhere'])->assertSessionHasNoErrors();

    expect($lead->fresh())->stage->toBe(LeadStage::Lost)->lost_reason->toBe('Bought elsewhere')->closed_at->not->toBeNull();
});

it('lets sales take a lead but only owners and managers assign others', function () {
    $lead = ($this->capture)();

    $this->actingAs($this->sales)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['assigned_to' => $this->owner->ulid])->assertForbidden();
    $this->actingAs($this->sales)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['assigned_to' => $this->sales->ulid])->assertSessionHasNoErrors();
    expect($lead->fresh()->assigned_to)->toBe($this->sales->id);

    $this->actingAs($this->owner)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['assigned_to' => User::factory()->create()->ulid])->assertSessionHasErrors('assigned_to');
    $this->actingAs($this->owner)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['assigned_to' => $this->owner->ulid])->assertSessionHasNoErrors();
    expect($lead->fresh()->assigned_to)->toBe($this->owner->id);
});

it('shows the lead page with the chat, notes and masked phone', function () {
    $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Hello']);
    $lead = Lead::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.leads.notes.store', [$this->lot, $lead]), ['body' => 'Wants it by Friday'])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get(route('dealer.leads.show', [$this->lot, $lead]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Leads/Show')
            ->where('lead.full_name', 'Chioma Okafor')
            ->has('messages', 1)
            ->where('notes.0.body', 'Wants it by Friday')
            ->has('staff', 2));

    expect(Conversation::sole()->lot_read_at)->not->toBeNull();
});

it('reminds the assigned rep when a follow-up is due, once', function () {
    $lead = ($this->capture)();
    $this->actingAs($this->owner)->patch(route('dealer.leads.update', [$this->lot, $lead]), ['assigned_to' => $this->sales->ulid, 'next_follow_up_at' => '2026-10-05T11:00'])->assertSessionHasNoErrors();

    expect($lead->fresh()->next_follow_up_at->toIso8601String())->toBe('2026-10-05T10:00:00+00:00');

    $this->artisan('leads:follow-up-reminders');
    expect($this->whatsapp->to('+2348020000002', 'follow_up_due'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:05', 'UTC'));
    $this->artisan('leads:follow-up-reminders');
    $this->artisan('leads:follow-up-reminders');

    expect($this->whatsapp->to('+2348020000002', 'follow_up_due'))->toHaveCount(1)
        ->and($this->whatsapp->to('+2348020000001', 'follow_up_due'))->toBe([])
        ->and($this->sales->notifications()->where('data->kind', 'follow_up')->count())->toBe(1);
});

it('closes leads when the car is delivered', function () {
    $lead = ($this->capture)();
    $rival = ($this->capture)(LeadSource::Call, null, User::factory()->create(['phone' => '+2348035550009']));

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Chioma Okafor', 'phone' => '08035550001',
    ])->assertSessionHasNoErrors();
    $order = SalesOrder::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.payments.store', [$this->lot, $order]), ['amount' => (string) intdiv($order->balance, 100), 'method' => 'cash'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->patch(route('dealer.manager.orders.update', [$this->lot, $order]), ['status' => 'delivered'])->assertSessionHasNoErrors();

    expect($lead->fresh()->stage)->toBe(LeadStage::Won)
        ->and($rival->fresh())->stage->toBe(LeadStage::Lost)->lost_reason->toBe('Car sold');
});

it('keeps leads inside their lot', function () {
    $lead = ($this->capture)();
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);

    $this->actingAs($otherOwner)->get(route('dealer.leads.show', [$otherLot, $lead]))->assertNotFound();
    $this->actingAs($otherOwner)->patch(route('dealer.leads.update', [$otherLot, $lead]), ['stage' => 'won'])->assertNotFound();
    $this->actingAs($otherOwner)->post(route('dealer.leads.messages.store', [$otherLot, $lead]), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($otherOwner)->get(route('dealer.leads.show', [$this->lot, $lead]))->assertForbidden();
    $this->actingAs($otherOwner)->get(route('dealer.leads.index', $otherLot))
        ->assertInertia(fn (Assert $page) => $page->has('columns.0.leads', 0));
});
