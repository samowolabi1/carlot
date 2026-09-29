<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Notifications\NewStockAtLot;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);
});

it('lists notifications and marks them read', function () {
    app(CaptureLead::class)->run($this->lot, $this->buyer, LeadSource::Chat, Vehicle::factory()->available()->create(['lot_id' => $this->lot->id]));

    $this->actingAs($this->owner)->get(route('dealer.dashboard', $this->lot))->assertInertia(fn (Assert $page) => $page->where('unread.notifications', 1));

    $this->actingAs($this->owner)->get(route('notifications'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Notifications')
            ->where('items.0.kind', 'lead')
            ->where('items.0.new', true)
            ->where('items.0.text', fn (string $text) => str_contains($text, 'Chioma Okafor')));

    expect($this->owner->unreadNotifications()->count())->toBe(0);
    $this->get(route('notifications'))->assertInertia(fn (Assert $page) => $page->where('unread.notifications', 0)->where('items.0.new', false));
});

it('shows buyers and dealers their own notification settings', function () {
    $this->actingAs($this->buyer)->get(route('notifications.settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/NotificationSettings')
            ->where('types', fn ($types) => collect($types)->pluck('type')->all() === ['messages', 'bookings', 'offers', 'new_stock', 'reviews']));

    $this->actingAs($this->owner)->get(route('notifications.settings'))
        ->assertInertia(fn (Assert $page) => $page->where('types', fn ($types) => collect($types)->pluck('type')->all() === ['messages', 'bookings', 'offers', 'leads', 'billing', 'summary', 'reviews']));
});

it('saves preferences and stops WhatsApp/SMS for that type only', function () {
    $this->actingAs($this->buyer)->put(route('notifications.update'), [
        'preferences' => ['new_stock' => ['phone' => false, 'mail' => true]],
    ])->assertSessionHasNoErrors();

    expect(NotificationPreferences::for($this->buyer->fresh())['new_stock'])->toBe(['phone' => false, 'mail' => true])
        ->and(NotificationPreferences::for($this->buyer->fresh())['bookings'])->toBe(['phone' => true, 'mail' => true]);

    Notification::fake();
    $this->buyer->refresh()->notify(new NewStockAtLot($this->lot, 2, '2018 Toyota Camry'));
    Notification::assertSentTo($this->buyer, NewStockAtLot::class, fn ($n, array $channels) => $channels === ['database']);
});

it('keeps the notification pages for signed-in users', function () {
    $this->get(route('notifications'))->assertRedirect(route('login'));
    $this->get(route('notifications.settings'))->assertRedirect(route('login'));
    $this->get(route('conversations.index'))->assertRedirect(route('login'));
});
