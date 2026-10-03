<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Lots\Models\Plan;
use App\Domain\Lots\Notifications\BankDetailsChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001', 'email' => 'emeka@example.com']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id')]);
    $this->manager = User::factory()->staff()->create();
    $this->lot->members()->attach($this->manager, ['role' => LotRole::Manager->value, 'accepted_at' => now()]);
    $this->account = ['bank_name' => 'GTBank', 'account_number' => '0123 456 789', 'account_name' => 'Prime Motors Ltd'];
});

it('lets only the owner manage bank details, audit-logs them and tells the managers', function () {
    Notification::fake();

    $this->actingAs($this->manager)->post(route('dealer.bank-accounts.store', $this->lot), $this->account)->assertForbidden();
    $this->actingAs($this->owner)->post(route('dealer.bank-accounts.store', $this->lot), $this->account)->assertSessionHasNoErrors();

    $account = LotBankAccount::withoutGlobalScopes()->sole();
    expect($account)->account_number->toBe('0123456789')->is_default->toBeTrue()
        ->and(AuditLog::where('action', 'lot.bank_account_added')->exists())->toBeTrue();
    Notification::assertSentTo([$this->owner, $this->manager], BankDetailsChanged::class);

    // A second account can be shown first; the first stops being the default.
    $this->post(route('dealer.bank-accounts.store', $this->lot), ['bank_name' => 'Opay', 'account_number' => '8012345678', 'account_name' => 'Prime Motors', 'is_default' => true]);
    expect($account->fresh()->is_default)->toBeFalse()
        ->and(LotBankAccount::preferredFor($this->lot->id)->bank_name)->toBe('Opay');

    $this->put(route('dealer.bank-accounts.update', [$this->lot, $account]), [...$this->account, 'account_name' => 'Prime Motors Limited'])->assertSessionHasNoErrors();
    expect($account->fresh()->account_name)->toBe('Prime Motors Limited');

    $this->delete(route('dealer.bank-accounts.destroy', [$this->lot, $account]))->assertSessionHasNoErrors();
    expect(LotBankAccount::withoutGlobalScopes()->count())->toBe(1)
        ->and(AuditLog::where('action', 'lot.bank_account_removed')->exists())->toBeTrue();

    // Managers see them in Settings but can't change them.
    $this->actingAs($this->manager)->get(route('dealer.settings', $this->lot))->assertInertia(fn (Assert $page) => $page
        ->where('bank.can_edit', false)
        ->where('bank.accounts.0.account_number', '8012345678'));
});

it('validates bank details', function () {
    $this->actingAs($this->owner)->post(route('dealer.bank-accounts.store', $this->lot), ['bank_name' => '', 'account_number' => '12345', 'account_name' => ''])
        ->assertSessionHasErrors(['bank_name', 'account_number', 'account_name']);

    $this->post(route('dealer.bank-accounts.store', $this->lot), $this->account);
    $this->post(route('dealer.bank-accounts.store', $this->lot), $this->account)->assertSessionHasErrors('account_number');

    foreach (['2222222222', '3333333333'] as $n) {
        $this->post(route('dealer.bank-accounts.store', $this->lot), [...$this->account, 'account_number' => $n]);
    }
    $this->post(route('dealer.bank-accounts.store', $this->lot), [...$this->account, 'account_number' => '4444444444'])->assertSessionHasErrors('account_number');
    expect(LotBankAccount::withoutGlobalScopes()->count())->toBe(LotBankAccount::MAX);
});

it('keeps bank accounts inside their lot', function () {
    $this->actingAs($this->owner)->post(route('dealer.bank-accounts.store', $this->lot), $this->account);
    $account = LotBankAccount::withoutGlobalScopes()->sole();
    $other = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);

    $this->actingAs($other)->put(route('dealer.bank-accounts.update', [$otherLot, $account]), [...$this->account, 'account_number' => '9999999999'])->assertNotFound();
    $this->actingAs($other)->delete(route('dealer.bank-accounts.destroy', [$otherLot, $account]))->assertNotFound();
    $this->actingAs($other)->get(route('dealer.settings', $otherLot))->assertInertia(fn (Assert $page) => $page->has('bank.accounts', 0));
    expect($account->fresh()->account_number)->toBe('0123456789');
});

it('shares the details from an order, on the customer\'s tracking page and in chat', function () {
    $this->actingAs($this->owner)->post(route('dealer.bank-accounts.store', $this->lot), $this->account);
    $car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);
    $this->post(route('dealer.manager.orders.store', $this->lot), ['vehicle' => $car->ulid, 'name' => 'Bola Ade', 'phone' => '08035550202'])->assertSessionHasNoErrors();
    $order = SalesOrder::withoutGlobalScopes()->sole();

    $this->get(route('dealer.manager.orders.show', [$this->lot, $order]))->assertInertia(fn (Assert $page) => $page
        ->where('bank.account.account_number', '0123456789')
        ->where('bank.share_text', fn (string $text) => str_contains($text, 'Account number: 0123456789') && str_contains($text, "Reference: {$order->order_no}") && str_contains($text, 'Amount: ₦10,000,000')));

    auth()->logout();
    $this->get(OrderLinks::track($order))->assertInertia(fn (Assert $page) => $page->where('bank.account_name', 'Prime Motors Ltd'));

    // A buyer's chat: "Send bank details" posts them as the seller's message.
    $buyer = User::factory()->create(['phone' => '+2348035550001']);
    $this->actingAs($buyer)->post(route('conversations.store'), ['vehicle' => $car->ulid]);
    $lead = Lead::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['preset' => 'bank'])->assertSessionHasNoErrors();
    expect(Message::query()->latest('id')->first()->body)->toContain('Pay Prime Motors directly')->toContain('0123456789');
});

it('never takes buyer payments: test drives book without a deposit', function () {
    $this->lot->update(['test_drive_deposit' => 1_000_000]); // an old setting from before
    $car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $buyer = User::factory()->create();
    $slot = CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String();

    $this->actingAs($buyer)->post(route('bookings.store'), ['lot' => $this->lot->slug, 'type' => 'test_drive', 'starts_at' => $slot, 'vehicle' => $car->ulid])
        ->assertRedirect();

    expect(Appointment::withoutGlobalScopes()->sole()->status)->not->toBe(AppointmentStatus::AwaitingDeposit)
        ->and(Payment::count())->toBe(0)
        ->and($this->payments->checkouts)->toBe([]);
});
