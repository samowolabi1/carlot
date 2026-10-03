<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Engagement\Actions\RunEngagementRules;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Notifications\EngagementNotice;
use App\Domain\Engagement\Support\EngagementRules;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use App\Filament\Pages\EngagementSettings;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    EngagementRules::flush();
    // Lot created 1 Sep; "now" is 10:00 in Lagos (the default send hour) on 5 Oct.
    $this->travelTo('2026-09-01 09:00');
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'email' => 'emeka@primemotors.ng']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->travelTo('2026-10-05 09:00');
    $this->owner->forceFill(['last_seen_at' => now()->subDay()])->save();

    $this->sent = fn (string $rule) => collect(Notification::sent($this->owner, EngagementNotice::class))
        ->filter(fn (EngagementNotice $n) => $n->message->rule === $rule)->values();
});

it('nudges an owner who hasn\'t signed in for 30 days, with the seller\'s figures', function () {
    Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->owner->forceFill(['last_seen_at' => now()->subDays(31)])->save();

    $this->artisan('engagement:run')->assertSuccessful();

    $notice = ($this->sent)('inactive_owner')->sole();
    expect($notice->subject)->toBe('Buyers are still looking at Prime Motors')
        ->and($notice->type)->toBe('nudges')
        ->and($notice->lines[0])->toStartWith('It\'s been a while since you signed in')
        ->and($notice->lines[1])->toBe('• Your cars are still on CarYard')
        ->and($notice->via($this->owner))->toBe(['database', 'mail'])
        ->and($notice->toMail($this->owner)->actionUrl)->toBe($notice->message->link());

    // Not again until the cooldown (30 days) is over.
    $this->travel(1)->day();
    $this->artisan('engagement:run');
    expect(($this->sent)('inactive_owner'))->toHaveCount(1);
});

it('only sends at the send hour in the seller\'s time zone', function () {
    $this->owner->forceFill(['last_seen_at' => now()->subDays(31)])->save();

    $this->travelTo('2026-10-05 13:00'); // 14:00 in Lagos
    $this->artisan('engagement:run');
    expect(EngagementMessage::count())->toBe(0);

    $this->artisan('engagement:run --now');
    expect(EngagementMessage::where('rule', 'inactive_owner')->count())->toBe(1);
});

it('reminds lots with no cars, and lists drafts left waiting', function () {
    $this->artisan('engagement:run');
    expect(($this->sent)('no_cars')->sole()->subject)->toBe('Add your first cars to Prime Motors');

    $this->travel(8)->days();
    Vehicle::factory()->count(2)->create(['lot_id' => $this->lot->id, 'status' => VehicleStatus::Draft, 'updated_at' => now()->subDays(4)]);
    $this->artisan('engagement:run');

    $drafts = ($this->sent)('drafts_waiting')->sole();
    expect($drafts->subject)->toBe('Cars at Prime Motors aren\'t live yet')
        ->and($drafts->lines)->toHaveCount(3)
        ->and($drafts->message->url)->toBe(route('dealer.vehicles.index', [$this->lot, 'status' => 'draft']))
        ->and(($this->sent)('no_cars'))->toHaveCount(1); // it has cars now
});

it('reminds lots that never finished setting up', function () {
    $this->lot->update(['status' => 'pending']);
    $this->artisan('engagement:run');

    expect(($this->sent)('setup_incomplete')->sole()->subject)->toBe('Finish setting up Prime Motors')
        ->and(($this->sent)('no_cars'))->toHaveCount(0);
});

it('sends a daily digest of buyers waiting, and nothing when nobody is', function () {
    $this->artisan('engagement:run');
    expect(($this->sent)('pending_actions'))->toHaveCount(0);

    Appointment::factory()->pending()->create(['lot_id' => $this->lot->id, 'created_at' => now()->subHours(6)]);
    $this->travel(1)->day();
    $this->artisan('engagement:run');

    $digest = ($this->sent)('pending_actions')->sole();
    expect($digest->subject)->toBe('Buyers are waiting at Prime Motors')
        ->and($digest->lines[1])->toBe('• 1 booking to confirm');
});

it('saves admin changes to the automated emails', function () {
    $admin = User::factory()->admin()->create(['email' => 'admin@lotlink.test']);
    $this->owner->forceFill(['last_seen_at' => now()->subDays(20)])->save();

    Livewire::actingAs($admin)->test(EngagementSettings::class)
        ->fillForm([
            'rules.no_cars.enabled' => false,
            'rules.inactive_owner.threshold' => 14,
            'rules.inactive_owner.subject' => '{name}, {lot} misses you',
        ])
        ->call('save')->assertHasNoFormErrors();

    expect(EngagementRules::get('inactive_owner'))->toMatchArray(['threshold' => 14, 'subject' => '{name}, {lot} misses you'])
        ->and(EngagementRules::get('no_cars')['enabled'])->toBeFalse();

    $this->artisan('engagement:run');
    expect(($this->sent)('inactive_owner')->sole()->subject)->toBe('Emeka, Prime Motors misses you')
        ->and(($this->sent)('no_cars'))->toHaveCount(0);

    // A test goes to the admin and doesn't count as the seller's reminder.
    app(RunEngagementRules::class)->test('drafts_waiting', $admin, $this->lot);
    Notification::assertSentTo($admin, EngagementNotice::class, fn (EngagementNotice $n) => str_starts_with($n->subject, '[Test] '));
    expect(EngagementMessage::where('user_id', $admin->id)->sole()->rule)->toBeNull();

    $this->actingAs($this->owner)->get('/admin/engagement/automated-emails')->assertForbidden();
    $this->actingAs($admin)->get('/admin/engagement/automated-emails')->assertOk()->assertSee('Owner hasn');
});

it('records when people last used CarYard, at most every 15 minutes', function () {
    $this->owner->forceFill(['last_seen_at' => null])->save();

    $this->actingAs($this->owner)->get(route('account'));
    $first = $this->owner->fresh()->last_seen_at;
    expect($first?->toDateTimeString())->toBe('2026-10-05 09:00:00');

    $this->travel(5)->minutes();
    $this->get(route('account'));
    expect($this->owner->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-05 09:00:00');

    $this->travel(20)->minutes();
    $this->get(route('account'));
    expect($this->owner->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-05 09:25:00');
});
