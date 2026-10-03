<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Actions\CreateLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 13:00', 'UTC')); // Saturday 14:00 in Lagos
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->walkIn = fn (array $data = []) => $this->actingAs($this->owner)->post(route('dealer.manager.walk-ins.store', $this->lot), [
        'name' => 'Ada Obi',
        'phone' => '0803 555 0101',
        ...$data,
    ]);
});

it('records a walk-in with just a name and phone', function () {
    ($this->walkIn)()->assertSessionHasNoErrors()->assertRedirect();

    $customer = LotCustomer::withoutGlobalScopes()->sole();
    expect($customer->phone)->toBe('+2348035550101')
        ->and($customer->lot_id)->toBe($this->lot->id)
        ->and($customer->consent_whatsapp)->toBeFalse()
        ->and(WalkIn::withoutGlobalScopes()->sole()->staff_id)->toBe($this->owner->id);
});

it('matches a returning visitor by phone however it is typed', function () {
    ($this->walkIn)(['name' => 'Ada'])->assertSessionHasNoErrors();
    ($this->walkIn)(['name' => 'Ada Obi', 'phone' => '+234 803 555 0101', 'consent_whatsapp' => true])->assertSessionHasNoErrors();

    $customer = LotCustomer::withoutGlobalScopes()->sole();
    expect($customer->name)->toBe('Ada Obi')
        ->and($customer->consent_whatsapp)->toBeTrue()
        ->and(WalkIn::withoutGlobalScopes()->count())->toBe(2);
});

it('links a customer who already has a CarYard account', function () {
    $buyer = User::factory()->create(['phone' => '+2348035550101']);

    ($this->walkIn)();

    expect(LotCustomer::withoutGlobalScopes()->sole()->user_id)->toBe($buyer->id);
});

it('creates a call-back task for the next working day at 10:00', function () {
    ($this->walkIn)(['next_step' => 'call_back', 'notes' => 'Wants a Camry'])->assertSessionHasNoErrors();

    $task = FollowUpTask::withoutGlobalScopes()->sole();
    // Sunday is closed by default, so Monday 10:00 Lagos (09:00 UTC).
    expect($task->due_at->toIso8601String())->toBe('2026-10-05T09:00:00+00:00')
        ->and($task->assigned_to)->toBe($this->owner->id)
        ->and($task->note)->toBe('Wants a Camry');
});

it('records the cars they looked at from this seller only', function () {
    $mine = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]);
    $theirs = Vehicle::factory()->available()->create();

    ($this->walkIn)(['vehicles' => [$mine->ulid, $theirs->ulid]])->assertSessionHasNoErrors();

    expect(WalkIn::withoutGlobalScopes()->sole()->vehicles_viewed)->toBe([$mine->id]);
});

it('rejects a phone number that is not valid', function () {
    ($this->walkIn)(['phone' => '12345'])->assertSessionHasErrors('phone');

    expect(LotCustomer::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps one walk-in when the same form is sent twice', function () {
    $uuid = (string) Str::uuid();

    ($this->walkIn)(['client_uuid' => $uuid, 'next_step' => 'call_back']);
    ($this->walkIn)(['client_uuid' => $uuid, 'next_step' => 'call_back']);

    expect(WalkIn::withoutGlobalScopes()->count())->toBe(1)
        ->and(FollowUpTask::withoutGlobalScopes()->count())->toBe(1);
});

it('shows today\'s walk-ins, follow-ups and balances', function () {
    ($this->walkIn)(['next_step' => 'call_back']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30', 'UTC'));
    ($this->walkIn)(['name' => 'Bola', 'phone' => '08035550202']);

    $this->actingAs($this->owner)->get(route('dealer.manager.today', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Manager/Today')
            ->where('stats.walk_ins', 1)
            ->has('walkIns', 1)
            ->where('walkIns.0.customer.name', 'Bola')
            ->has('tasks', 1)
            ->where('tasks.0.overdue', true));
});

it('marks a follow-up done or moves it to tomorrow', function () {
    ($this->walkIn)(['next_step' => 'call_back']);
    $task = FollowUpTask::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->patch(route('dealer.manager.tasks.update', [$this->lot, $task]), ['action' => 'tomorrow'])->assertRedirect();
    expect($task->fresh()->due_at->toIso8601String())->toBe('2026-10-06T09:00:00+00:00');

    $this->actingAs($this->owner)->patch(route('dealer.manager.tasks.update', [$this->lot, $task]), ['action' => 'done'])->assertRedirect();
    expect($task->fresh()->done_at)->not->toBeNull();
});

it('reminds staff when a follow-up comes due, once', function () {
    ($this->walkIn)(['next_step' => 'call_back']);
    $this->owner->update(['phone' => '+2348020000001']);

    $this->artisan('manager:follow-up-reminders')->assertSuccessful();
    expect($this->whatsapp->to('+2348020000001', 'follow_up_due'))->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05', 'UTC'));
    $this->artisan('manager:follow-up-reminders')->assertSuccessful();
    $this->artisan('manager:follow-up-reminders')->assertSuccessful();

    $sent = $this->whatsapp->to('+2348020000001', 'follow_up_due');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params[2])->toBe('Ada Obi (0803 555 0101)');
});

it('shows a customer\'s timeline and lets staff edit tags and consent', function () {
    ($this->walkIn)(['next_step' => 'call_back']);
    $customer = LotCustomer::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->get(route('dealer.manager.customers.show', [$this->lot, $customer]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Manager/Customer')
            ->has('timeline', 2)
            ->where('customer.phone', '+2348035550101'));

    $this->actingAs($this->owner)->patch(route('dealer.manager.customers.update', [$this->lot, $customer]), [
        'name' => 'Ada Obi', 'source' => 'instagram', 'tags' => ['hot', 'cash buyer'], 'budget_max' => '₦9,000,000', 'consent_whatsapp' => true,
    ])->assertSessionHasNoErrors();

    $customer->refresh();
    expect($customer->tags)->toBe(['hot', 'cash buyer'])
        ->and($customer->budget_max)->toBe(900_000_000)
        ->and($customer->consent_whatsapp)->toBeTrue();

    $this->actingAs($this->owner)->get(route('dealer.manager.customers.index', ['lot' => $this->lot, 'tag' => 'hot', 'q' => '0803 555']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
});
