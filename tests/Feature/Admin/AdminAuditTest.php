<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Admin\Impersonation;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotStatus;
use App\Filament\Resources\LotResource;
use App\Filament\Resources\LotResource\Pages\ListLots;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ops Admin', 'email' => 'ops@lotlink.test', 'password' => 'admin-password-1']);
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001', 'password' => 'owner-password-1']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
});

it('lets admins open any seller (the seller policy no longer applies in the admin)', function () {
    $this->actingAs($this->admin)->get(LotResource::getUrl('view', ['record' => $this->lot]))->assertOk()->assertSee('Prime Motors');
    Livewire::test(ListLots::class)->assertTableActionVisible('view', $this->lot)->assertTableActionVisible('impersonate', $this->lot);

    $this->actingAs($this->owner)->get('/admin/lots')->assertForbidden();
});

it('lists lots without a membership query per row', function () {
    app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Second Lot']);
    app(CreateLot::class)->run(User::factory()->staff()->create(), ['name' => 'Third Lot']);
    $this->actingAs($this->admin);

    DB::enableQueryLog();
    Livewire::test(ListLots::class)->assertCanSeeTableRecords([$this->lot]);
    $memberships = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'lot_members'))->count();

    expect($memberships)->toBe(0);
});

it('goes back to the admin panel with a full page load, and the admin stays signed in', function () {
    $this->actingAs($this->admin);
    Livewire::test(ListLots::class)->callTableAction('impersonate', $this->lot)->assertRedirect(route('dealer.dashboard', $this->lot));
    expect(auth()->id())->toBe($this->owner->id);

    // The seller's page (Inertia) asks for "Back to admin": a location response, not a redirect Inertia follows.
    $this->withHeader('X-Inertia', 'true')->post(route('impersonation.stop'))
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/admin'));

    // The panel doesn't log the admin out over the seller's password hash left in the session.
    $this->withHeaders(['X-Inertia' => ''])->get('/admin')->assertOk();
    expect(auth()->id())->toBe($this->admin->id);
});

it('ends the support session on sign out instead of signing the admin out', function () {
    $this->actingAs($this->admin);
    Livewire::test(ListLots::class)->callTableAction('impersonate', $this->lot);

    $this->withHeader('X-Inertia', 'true')->post(route('logout'))->assertStatus(409)->assertHeader('X-Inertia-Location', url('/admin'));
    expect(auth()->id())->toBe($this->admin->id)->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    // A seller signing out normally is signed out.
    auth()->logout();
    $this->actingAs($this->owner)->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});

it('logs what happens during "Log in as" with the admin behind it', function () {
    // What the session holds during "Log in as" (the HTTP test client starts a fresh session each request).
    $this->actingAs($this->owner)->withSession([Impersonation::SESSION_KEY => $this->admin->id]);

    $this->post(route('dealer.bank-accounts.store', $this->lot), ['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Prime Motors Ltd'])->assertSessionHasNoErrors();

    $log = AuditLog::where('action', 'lot.bank_account_added')->sole();
    expect($log->user_id)->toBe($this->owner->id)->and($log->impersonator_id)->toBe($this->admin->id);

    // The owner's own changes carry no impersonator.
    $this->flushSession();
    $this->actingAs($this->owner)->post(route('dealer.bank-accounts.store', $this->lot), ['bank_name' => 'Zenith', 'account_number' => '9876543210', 'account_name' => 'Prime Motors Ltd']);
    expect(AuditLog::where('action', 'lot.bank_account_added')->latest('id')->first()->impersonator_id)->toBeNull();
});

it('keeps the menu counts in one cached lookup and refreshes it when a queue changes', function () {
    $this->lot->forceFill(['status' => LotStatus::Pending, 'submitted_at' => now()])->save();
    expect(LotResource::getNavigationBadge())->toBe('1');

    DB::enableQueryLog();
    LotResource::getNavigationBadge();
    AdminCounters::all();
    expect(DB::getQueryLog())->toBe([]);

    $this->lot->forceFill(['status' => LotStatus::Active])->save();
    expect(LotResource::getNavigationBadge())->toBeNull();
});
