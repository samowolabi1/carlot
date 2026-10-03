<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('sends users without a seller to onboarding', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dealer.home'))
        ->assertRedirect(route('dealer.onboarding.start'));
});

it('creates a pending lot with the user as owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('dealer.lots.store'), [
        'name' => 'Prime Motors',
        'phone' => '0802 111 2233',
    ])->assertRedirect(route('dealer.onboarding.show', ['prime-motors', 'branding']));

    $lot = Lot::sole();
    expect($lot->slug)->toBe('prime-motors')
        ->and($lot->status)->toBe(LotStatus::Pending)
        ->and($lot->phone)->toBe('+2348021112233')
        ->and($lot->whatsapp)->toBe('+2348021112233')
        ->and($lot->plan->code)->toBe('starter')
        ->and($user->roleIn($lot))->toBe(LotRole::Owner)
        ->and($user->fresh()->role)->toBe(UserRole::Staff)
        ->and($lot->hours()->count())->toBe(7);
});

it('gives each seller a unique slug', function () {
    Lot::factory()->create(['slug' => 'prime-motors']);

    $this->actingAs(User::factory()->create())->post(route('dealer.lots.store'), ['name' => 'Prime Motors', 'phone' => '08021112233']);

    expect(Lot::latest('id')->first()->slug)->toBe('prime-motors-2');
});

it('walks through the wizard steps', function () {
    Storage::fake(config('lotlink.media_disk'));
    $lot = Lot::factory()->create(['latitude' => null, 'longitude' => null]);
    $owner = $lot->owner;

    $this->actingAs($owner)
        ->post(route('dealer.settings.branding', $lot), [
            'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
            'brand_color' => '#16302B',
            'onboarding' => true,
        ])
        ->assertRedirect(route('dealer.onboarding.show', [$lot, 'location']));

    Storage::disk(config('lotlink.media_disk'))->assertExists($lot->fresh()->logo_path);

    $this->actingAs($owner)
        ->put(route('dealer.settings.location', $lot), [
            'latitude' => 6.6018,
            'longitude' => 3.3515,
            'address' => '12 Allen Avenue',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'landmark' => 'Opposite the big church',
            'onboarding' => true,
        ])
        ->assertRedirect(route('dealer.onboarding.show', [$lot, 'hours']));

    expect($lot->fresh())->latitude->toBe(6.6018)->longitude->toBe(3.3515);

    $this->actingAs($owner)->post(route('dealer.onboarding.submit', $lot))->assertRedirect(route('dealer.dashboard', $lot));
    expect($lot->fresh()->submitted_at)->not->toBeNull()
        ->and($lot->fresh()->status)->toBe(LotStatus::Pending);
});

it('will not submit a seller without a map pin', function () {
    $lot = Lot::factory()->create(['latitude' => null, 'longitude' => null]);

    $this->actingAs($lot->owner)->post(route('dealer.onboarding.submit', $lot))
        ->assertRedirect(route('dealer.onboarding.show', [$lot, 'location']));

    expect($lot->fresh()->submitted_at)->toBeNull();
});

it('saves opening hours and booking rules', function () {
    $lot = Lot::factory()->create();

    $days = collect(range(0, 6))->map(fn ($d) => [
        'weekday' => $d,
        'is_closed' => $d === 0,
        'opens_at' => $d === 0 ? null : '08:30',
        'closes_at' => $d === 0 ? null : '17:30',
    ])->all();

    $this->actingAs($lot->owner)
        ->put(route('dealer.settings.hours', $lot), ['days' => $days, 'slot_minutes' => 45, 'slot_capacity' => 3])
        ->assertSessionHasNoErrors();

    $monday = LotHour::withoutGlobalScopes()->where('lot_id', $lot->id)->where('weekday', 1)->sole();
    expect($monday->opens_at)->toStartWith('08:30')
        ->and($monday->slot_minutes)->toBe(45)
        ->and($monday->slot_capacity)->toBe(3)
        ->and(LotHour::withoutGlobalScopes()->where('lot_id', $lot->id)->where('weekday', 0)->value('is_closed'))->toBeTruthy();
});

it('rejects closing before opening', function () {
    $lot = Lot::factory()->create();
    $days = collect(range(0, 6))->map(fn ($d) => ['weekday' => $d, 'is_closed' => false, 'opens_at' => '18:00', 'closes_at' => '09:00'])->all();

    $this->actingAs($lot->owner)
        ->put(route('dealer.settings.hours', $lot), ['days' => $days, 'slot_minutes' => 30, 'slot_capacity' => 2])
        ->assertSessionHasErrors('days.0.closes_at');
});

it('renders the dashboard with a setup checklist', function () {
    $lot = Lot::factory()->create();

    $this->actingAs($lot->owner)->get(route('dealer.dashboard', $lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Dashboard')
            ->has('checklist', 6)
            ->where('currentLot.slug', $lot->slug)
            ->where('currentLot.role', 'owner'));
});

it('renders every onboarding step', function (string $step) {
    $lot = Lot::factory()->create();

    $this->actingAs($lot->owner)->get(route('dealer.onboarding.show', [$lot, $step]))
        ->assertInertia(fn (Assert $page) => $page->component('Dealer/Onboarding')->where('step', $step));
})->with(['business', 'branding', 'location', 'hours', 'staff', 'submit']);

it('does not let sales staff change lot settings', function () {
    $lot = Lot::factory()->create();
    $sales = User::factory()->staff()->create();
    $lot->members()->attach($sales, ['role' => 'sales', 'accepted_at' => now()]);

    $this->actingAs($sales)->get(route('dealer.dashboard', $lot))->assertOk();
    $this->actingAs($sales)->get(route('dealer.settings', $lot))->assertForbidden();
    $this->actingAs($sales)->put(route('dealer.settings.profile', $lot), ['name' => 'Hijack', 'phone' => '08021112233'])->assertForbidden();
});
