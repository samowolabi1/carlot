<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Engagement\Models\Broadcast;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Notifications\EngagementNotice;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\NotificationPreferences;
use App\Filament\Resources\BroadcastResource\Pages\CreateBroadcast;
use App\Filament\Resources\BroadcastResource\Pages\ListBroadcasts;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@lotlink.test']);
    $make = function (string $name, string $state, string $phone) {
        $owner = User::factory()->staff()->create(['name' => "{$name} Owner", 'email' => str($name)->slug().'@lots.ng', 'phone' => $phone]);
        $lot = app(CreateLot::class)->run($owner, ['name' => $name, 'phone' => $phone, 'state' => $state]);
        $lot->update(['status' => 'active', 'state' => $state]);

        return [$owner, $lot];
    };
    [$this->lagosOwner, $this->lagosLot] = $make('Prime Motors', 'Lagos', '+2348021110001');
    [$this->kanoOwner, $this->kanoLot] = $make('Arewa Autos', 'Kano', '+2348021110002');
    // The Lagos owner has a second lot: still one message.
    $this->secondLot = app(CreateLot::class)->run($this->lagosOwner, ['name' => 'Prime Motors Lekki', 'phone' => '+2348021110003']);
    $this->secondLot->update(['status' => 'active', 'state' => 'Lagos']);
    $this->manager = User::factory()->staff()->create(['email' => 'manager@lots.ng']);
    $this->lagosLot->members()->attach($this->manager, ['role' => LotRole::Manager->value, 'accepted_at' => now()]);
    Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->kanoLot->id]);

    $this->create = fn (array $data) => Livewire::actingAs($this->admin)->test(CreateBroadcast::class)
        ->fillForm(['title' => 'Share cars to WhatsApp status', 'body' => "New: one tap to share.\n\nTry it today.", 'cta_label' => 'Try it', 'channels' => ['mail', 'push'], ...$data])
        ->call('create')->assertHasNoFormErrors();
});

it('writes a broadcast to a group of lots, shows its reach and sends it once', function () {
    Notification::fake();
    $this->actingAs($this->admin);

    ($this->create)(['audience' => ['states' => ['Lagos'], 'status' => 'active', 'managers' => true]]);
    $broadcast = Broadcast::sole();
    expect($broadcast)->status->toBe('draft')->created_by->toBe($this->admin->id)
        ->and($broadcast->audience)->toMatchArray(['states' => ['Lagos'], 'managers' => true]);

    Livewire::test(ListBroadcasts::class)->callTableAction('send', $broadcast)->assertNotified('Sending');

    $broadcast->refresh();
    expect($broadcast)->status->toBe('sent')->recipients_count->toBe(2)
        ->and(EngagementMessage::pluck('user_id')->sort()->values()->all())->toBe(collect([$this->lagosOwner->id, $this->manager->id])->sort()->values()->all())
        ->and(AuditLog::where('action', 'admin.broadcast_sent')->exists())->toBeTrue();

    Notification::assertSentTo($this->lagosOwner, EngagementNotice::class, fn (EngagementNotice $n) => $n->type === 'news'
        && $n->subject === 'Share cars to WhatsApp status' && $n->lines === ['New: one tap to share.', 'Try it today.']
        && $n->via($this->lagosOwner) === ['database', 'mail']);
    Notification::assertNotSentTo($this->kanoOwner, EngagementNotice::class);

    // Sent broadcasts can't be sent (or edited) again.
    Livewire::test(ListBroadcasts::class)->assertTableActionHidden('send', $broadcast)->assertTableActionHidden('edit', $broadcast);
});

it('filters by plan, verification and activity', function () {
    Notification::fake();
    $this->actingAs($this->admin);
    $pro = Plan::where('code', 'pro')->first();
    $this->kanoLot->forceFill(['plan_id' => $pro->id, 'verified_at' => now()])->save();
    $this->lagosOwner->forceFill(['last_seen_at' => now()->subDays(45)])->save();

    ($this->create)(['title' => 'Pro tips', 'audience' => ['plans' => [$pro->id], 'verified' => 'yes']]);
    ($this->create)(['title' => 'We miss you', 'audience' => ['activity' => 'inactive']]);
    ($this->create)(['title' => 'Add cars', 'audience' => ['activity' => 'no_live_cars']]);

    foreach (Broadcast::all() as $b) {
        Livewire::test(ListBroadcasts::class)->callTableAction('send', $b);
    }
    $to = fn (string $title) => EngagementMessage::whereHas('broadcast', fn ($q) => $q->where('title', $title))->pluck('user_id')->all();

    expect($to('Pro tips'))->toBe([$this->kanoOwner->id])
        ->and($to('We miss you'))->toBe([$this->lagosOwner->id])
        ->and($to('Add cars'))->toBe([$this->lagosOwner->id]); // Arewa Autos has a car live
});

it('schedules a broadcast and sends it when due', function () {
    Notification::fake();
    $this->actingAs($this->admin);
    ($this->create)(['audience' => ['status' => 'active']]);
    $broadcast = Broadcast::sole();

    Livewire::test(ListBroadcasts::class)->callTableAction('schedule', $broadcast, ['at' => '2026-10-05 15:00']);
    expect($broadcast->fresh())->status->toBe('scheduled')
        ->and($broadcast->fresh()->scheduled_at->toDateTimeString())->toBe('2026-10-05 14:00:00'); // 15:00 Lagos

    $this->artisan('engagement:send-broadcasts');
    expect($broadcast->fresh()->status)->toBe('scheduled');

    $this->travelTo('2026-10-05 14:01');
    $this->artisan('engagement:send-broadcasts');
    expect($broadcast->fresh())->status->toBe('sent')->recipients_count->toBe(2);
});

it('tracks clicks, and lets people unsubscribe from news emails', function () {
    $this->actingAs($this->admin);
    ($this->create)(['cta_url' => 'https://lotlink.ng/blog/whatsapp-status', 'audience' => ['states' => ['Kano']]]);
    Livewire::test(ListBroadcasts::class)->callTableAction('send', Broadcast::sole());

    $message = EngagementMessage::sole();
    expect($this->kanoOwner->notifications()->sole()->data)->toMatchArray(['kind' => 'news', 'text' => 'Share cars to WhatsApp status', 'url' => route('engagement.click', $message)]);

    auth()->logout();
    $this->get(route('engagement.click', $message))->assertRedirect('https://lotlink.ng/blog/whatsapp-status');
    expect($message->fresh()->clicked_at)->not->toBeNull();
    Livewire::actingAs($this->admin)->test(ListBroadcasts::class)->assertTableColumnStateSet('opened', '1 (100%)', Broadcast::sole());

    auth()->logout();
    $this->get(route('engagement.unsubscribe', ['user' => $this->kanoOwner->ulid, 'type' => 'news']))->assertForbidden();
    $this->get(URL::signedRoute('engagement.unsubscribe', ['user' => $this->kanoOwner->ulid, 'type' => 'news']))
        ->assertOk()->assertInertia(fn ($page) => $page->component('Engagement/Unsubscribed'));
    expect(NotificationPreferences::for($this->kanoOwner->fresh())['news'])->toBe(['phone' => true, 'mail' => false, 'push' => true]);

    $notice = new EngagementNotice($message, 'news', 'x', [], 'Go', ['mail']);
    expect($notice->via($this->kanoOwner->fresh()))->toBe(['database']);
});

it('sends by WhatsApp only when chosen, with the announcement template', function () {
    $this->actingAs($this->admin);
    ($this->create)(['channels' => ['whatsapp'], 'audience' => ['states' => ['Kano']]]);
    Livewire::test(ListBroadcasts::class)->callTableAction('send', Broadcast::sole());

    $sent = $this->whatsapp->to('+2348021110002', 'lot_announcement');
    expect($sent)->toHaveCount(1)->and($sent[0]->params)->toBe(['Arewa', 'Share cars to WhatsApp status'])
        ->and($this->whatsapp->to('+2348021110001', 'lot_announcement'))->toBe([]);
});

it('keeps broadcasts to admins', function () {
    $this->actingAs($this->lagosOwner)->get('/admin/broadcasts')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/broadcasts')->assertOk();
});
