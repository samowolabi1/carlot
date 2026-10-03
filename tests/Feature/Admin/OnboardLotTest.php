<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Mail\LotWelcome;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Filament\Resources\LotResource\Pages\ListLots;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('onboards a seller who signs in by WhatsApp, and welcomes them there', function () {
    Livewire::test(ListLots::class)->callAction('onboard', [
        'owner_name' => 'Musa Bello',
        'sign_in' => 'whatsapp',
        'phone' => '0803 555 1212',
        'lot_name' => 'Bello Motors Kano',
        'state' => 'Kano',
        'city' => 'Nassarawa GRA',
        'plan_id' => Plan::where('code', 'starter')->value('id'),
        'approve' => true,
    ])->assertHasNoActionErrors();

    $lot = Lot::where('name', 'Bello Motors Kano')->sole();
    $owner = User::where('phone', '+2348035551212')->sole();

    expect($lot)->state->toBe('Kano')->city->toBe('Nassarawa GRA')->phone->toBe('+2348035551212')->status->toBe(LotStatus::Active)
        ->onboarded_by->toBe($this->admin->id)->plan_id->toBe(Plan::where('code', 'starter')->value('id'))
        ->and($owner)->name->toBe('Musa Bello')->role->toBe(UserRole::Staff)->email->toBeNull()
        ->and($owner->roleIn($lot))->toBe(LotRole::Owner)
        ->and($this->whatsapp->to('+2348035551212', 'lot_welcome'))->toHaveCount(1)
        ->and($this->sms->sent)->toBe([])
        ->and(AuditLog::where('action', 'admin.lot_onboarded')->exists())->toBeTrue();

    // The owner signs in with that number and lands on their lot.
    auth()->logout();
    $this->post(route('login.send'), ['phone' => '0803 555 1212']);
    $this->post(route('login.check'), ['code' => $this->lastCode('+2348035551212')]);
    $this->assertAuthenticatedAs($owner);
    // The account was made for them, so they accept the Terms and Privacy Policy first, then carry on.
    $this->get(route('dealer.dashboard', $lot))->assertRedirect(route('legal.accept'));
    $this->post(route('legal.accept.store'), ['agree' => true])->assertRedirect(route('dealer.dashboard', $lot));
    $this->get(route('dealer.dashboard', $lot))->assertOk();
});

it('onboards an owner who signs in by email, pending approval', function () {
    Livewire::test(ListLots::class)->callAction('onboard', [
        'owner_name' => 'Ngozi Eze',
        'sign_in' => 'email',
        'email' => 'Ngozi@EzeAutos.ng',
        'lot_name' => 'Eze Autos',
        'lot_phone' => '0809 111 2222',
        'state' => 'Enugu',
        'city' => 'Independence Layout',
    ])->assertHasNoActionErrors();

    $lot = Lot::where('name', 'Eze Autos')->sole();
    expect($lot)->status->toBe(LotStatus::Pending)->state->toBe('Enugu')
        ->and(User::where('email', 'ngozi@ezeautos.ng')->sole()->phone)->toBeNull();
    Mail::assertQueued(LotWelcome::class, fn (LotWelcome $mail) => $mail->hasTo('ngozi@ezeautos.ng') && str_contains($mail->url, 'method=email'));
    expect($this->whatsapp->sent)->toBe([]);
});

it('uses an existing account for the owner and checks the details', function () {
    $existing = User::factory()->create(['phone' => '+2348035551313', 'name' => 'Ada']);

    Livewire::test(ListLots::class)->callAction('onboard', [
        'owner_name' => 'Ada Obi', 'sign_in' => 'whatsapp', 'phone' => '08035551313', 'lot_name' => 'Ada Cars', 'state' => 'FCT', 'city' => 'Garki',
    ])->assertHasNoActionErrors();
    expect(Lot::where('name', 'Ada Cars')->sole()->owner_id)->toBe($existing->id)
        ->and(User::count())->toBe(2);

    Livewire::test(ListLots::class)->callAction('onboard', [
        'owner_name' => 'X', 'sign_in' => 'email', 'lot_name' => 'No Email Cars', 'state' => 'Lagos', 'city' => 'Ikeja',
    ])->assertHasActionErrors(['email']);

    // Admin accounts can't be made sellers this way.
    Livewire::test(ListLots::class)->callAction('onboard', [
        'owner_name' => 'X', 'sign_in' => 'email', 'email' => $this->admin->email, 'lot_name' => 'Admin Cars', 'phone' => '08035551414', 'state' => 'Lagos', 'city' => 'Ikeja',
    ]);
    expect(Lot::where('name', 'Admin Cars')->exists())->toBeFalse();
});

it('keeps states to the list: Nigeria\'s 36 states and the FCT', function () {
    expect(config('lotlink.regions'))->toHaveCount(37)->toContain('FCT', 'Kano', 'Lagos', 'Rivers', 'Zamfara');

    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->actingAs($owner);
    $location = ['latitude' => 6.6, 'longitude' => 3.35, 'address' => '1 Allen Avenue', 'city' => 'Ikeja'];

    $this->put(route('dealer.settings.location', $lot), [...$location, 'state' => 'Lagos State'])->assertSessionHasNoErrors();
    expect($lot->fresh()->state)->toBe('Lagos');
    $this->put(route('dealer.settings.location', $lot), [...$location, 'state' => 'Abuja'])->assertSessionHasNoErrors();
    expect($lot->fresh()->state)->toBe('FCT');
    $this->put(route('dealer.settings.location', $lot), [...$location, 'state' => 'Atlantis'])->assertSessionHasErrors('state');
});
