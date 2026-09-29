<?php

use App\Domain\Accounts\Actions\DeleteAccount;
use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Notifications\UnreadMessages;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Push\Models\PushSubscription;
use App\Domain\Support\NotificationPreferences;

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

beforeEach(function () {
    config(['services.webpush.public_key' => 'BPublicKey', 'services.webpush.private_key' => 'private']);
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);

    // The browser's side of "Turn on": its push subscription, sent to us.
    $this->subscribe = function (User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc', string $ua = ANDROID) {
        return $this->actingAs($user)->withHeader('User-Agent', $ua)
            ->postJson(route('push.store'), ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'secret'], 'contentEncoding' => 'aes128gcm']);
    };
});

it('turns push on for a device and names it', function () {
    ($this->subscribe)($this->owner)->assertNoContent();

    $sub = PushSubscription::sole();
    expect($sub)->user_id->toBe($this->owner->id)->device->toBe('Chrome on Android')->public_key->toBe('BKey')
        ->and(session('push_endpoint'))->toBe('https://fcm.googleapis.com/fcm/send/abc');

    // The same browser sending it again (keys rotate) updates the row, never duplicates it.
    ($this->subscribe)($this->owner)->assertNoContent();
    expect(PushSubscription::count())->toBe(1);

    $this->actingAs($this->owner)->get(route('notifications.settings'))->assertInertia(fn ($page) => $page
        ->where('push.key', 'BPublicKey')
        ->where('push.devices.0.device', 'Chrome on Android')->where('push.devices.0.current', true)
        ->where('preferences.bookings.push', true));
});

it('only accepts secure push endpoints', function () {
    ($this->subscribe)($this->owner, 'http://evil.test/push')->assertJsonValidationErrors('endpoint');
    $this->actingAs($this->owner)->postJson(route('push.store'), ['endpoint' => 'https://fcm.googleapis.com/x'])->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
    auth()->logout();
    $this->postJson(route('push.store'))->assertUnauthorized();
});

it('pushes any notification the person gets, with its text and link', function () {
    ($this->subscribe)($this->owner);

    $this->owner->notify(new BillingNotice($this->lot, 'Your Pro plan renews on 1 Nov.'));

    $pushed = $this->push->to('https://fcm.googleapis.com/fcm/send/abc');
    expect($pushed)->toHaveCount(1)
        ->and($pushed[0]['title'])->toBe('Billing')
        ->and($pushed[0]['body'])->toContain('Your Pro plan renews on 1 Nov.')
        ->and($pushed[0]['url'])->toBe($this->owner->notifications()->sole()->data['url']);
    expect(PushSubscription::sole()->last_used_at)->not->toBeNull();
});

it('respects the per-type push switch and skips people with no device', function () {
    expect(NotificationPreferences::filter($this->owner, 'billing', ['phone', 'database']))->toBe(['phone', 'database']);

    ($this->subscribe)($this->owner);
    expect(NotificationPreferences::filter($this->owner->fresh(), 'billing', ['phone', 'database']))->toBe(['phone', 'database', 'push']);

    $prefs = NotificationPreferences::for($this->owner);
    $prefs['billing']['push'] = false;
    $this->actingAs($this->owner)->put(route('notifications.update'), ['preferences' => $prefs])->assertSessionHas('success');

    expect($this->owner->fresh()->notification_preferences['billing'])->toEqual(['phone' => true, 'mail' => true, 'push' => false])
        ->and(NotificationPreferences::filter($this->owner->fresh(), 'billing', ['phone', 'database']))->toBe(['phone', 'database']);
    $this->owner->fresh()->notify(new BillingNotice($this->lot, 'Renewal'));
    expect($this->push->sent)->toBe([]);
});

it('forgets devices the push service says are gone', function () {
    ($this->subscribe)($this->owner, 'https://fcm.googleapis.com/fcm/send/old');
    ($this->subscribe)($this->owner, 'https://updates.push.services.mozilla.com/wpush/new', 'Mozilla/5.0 (Windows NT 10.0; rv:131.0) Gecko/20100101 Firefox/131.0');
    $this->push->gone = ['https://fcm.googleapis.com/fcm/send/old'];

    $this->owner->notify(new BillingNotice($this->lot, 'Renewal'));

    expect($this->push->sent)->toHaveCount(2)
        ->and(PushSubscription::pluck('device')->all())->toBe(['Firefox on Windows']);
});

it('pushes chat messages to the other side straight away, and not again with the unread reminder', function () {
    $car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    ($this->subscribe)($this->owner, 'https://push.example/owner');
    ($this->subscribe)($this->buyer, 'https://push.example/buyer');

    // The first message comes as the "New lead" push (not twice).
    $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => $car->ulid, 'body' => 'Is it still available?']);
    $conversation = Conversation::sole();
    $lead = Lead::withoutGlobalScopes()->sole();
    expect(array_column($this->push->to('https://push.example/owner'), 'title'))->toBe(['New lead']);

    $this->travel(2)->minutes();
    $this->actingAs($this->buyer)->post(route('conversations.reply', $conversation), ['body' => 'Can I come on Saturday?']);
    expect(array_slice($this->push->to('https://push.example/owner'), 1))->toBe([[
        'title' => 'Chioma Okafor',
        'body' => 'Can I come on Saturday?',
        'url' => route('dealer.leads.show', [$this->lot->slug, $lead->ulid]),
        'tag' => "chat-{$conversation->ulid}",
    ]]);

    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['body' => 'Yes, come and see it']);
    expect($this->push->to('https://push.example/buyer'))->toHaveCount(1)
        ->and($this->push->to('https://push.example/buyer')[0])->toMatchArray(['title' => 'Prime Motors', 'body' => 'Yes, come and see it', 'url' => route('conversations.show', $conversation)]);

    // The WhatsApp reminder 10 minutes later doesn't push a second time.
    expect((new UnreadMessages($conversation, 'Prime Motors', 'Yes', 'https://x'))->via($this->buyer->fresh()))->toBe(['phone']);
});

it('doesn\'t push chat when "Chat messages" push is off', function () {
    $car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    ($this->subscribe)($this->owner, 'https://push.example/owner');
    $this->owner->forceFill(['notification_preferences' => ['messages' => ['push' => false]]])->save();

    $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => $car->ulid, 'body' => 'Hello']);
    $this->travel(2)->minutes();
    $this->actingAs($this->buyer)->post(route('conversations.reply', Conversation::sole()), ['body' => 'Are you there?']);

    expect(array_column($this->push->to('https://push.example/owner'), 'title'))->toBe(['New lead']); // leads push still on
});

it('stops a device\'s pushes when turned off or on sign-out, and lets people remove other devices', function () {
    ($this->subscribe)($this->owner, 'https://push.example/phone');
    ($this->subscribe)($this->owner, 'https://push.example/laptop');
    $this->actingAs($this->buyer);
    ($this->subscribe)($this->buyer, 'https://push.example/buyer');

    // Turned off in the browser.
    $this->actingAs($this->owner)->deleteJson(route('push.destroy'), ['endpoint' => 'https://push.example/laptop'])->assertNoContent();
    expect(PushSubscription::where('user_id', $this->owner->id)->count())->toBe(1);

    // Someone else's device can't be removed.
    $buyerDevice = PushSubscription::where('user_id', $this->buyer->id)->sole();
    $this->actingAs($this->owner)->delete(route('push.forget', $buyerDevice->endpoint_hash));
    expect($buyerDevice->fresh())->not->toBeNull();

    // Removing one of your own from the list.
    $phone = PushSubscription::where('user_id', $this->owner->id)->sole();
    $this->actingAs($this->owner)->delete(route('push.forget', $phone->endpoint_hash))->assertSessionHas('success');
    expect($phone->fresh())->toBeNull();

    // Signing out on a device stops that device's pushes.
    $this->actingAs($this->buyer);
    ($this->subscribe)($this->buyer, 'https://push.example/buyer');
    $this->post(route('logout'));
    expect(PushSubscription::count())->toBe(0);
});

it('sends a test push, and removes devices when the account is deleted', function () {
    $this->actingAs($this->buyer)->post(route('push.test'))->assertSessionHas('error');

    ($this->subscribe)($this->buyer, 'https://push.example/buyer');
    $this->actingAs($this->buyer)->post(route('push.test'))->assertSessionHas('success');
    expect($this->push->to('https://push.example/buyer')[0]['body'])->toBe('Push notifications work on this device.');

    app(DeleteAccount::class)->run($this->buyer);
    expect(PushSubscription::count())->toBe(0);
});

it('offers push only once the keys are set, and makes keys with push:vapid', function () {
    $this->actingAs($this->buyer)->get(route('notifications'))->assertInertia(fn ($page) => $page->where('pushKey', 'BPublicKey'));

    config(['services.webpush.private_key' => null]);
    $this->actingAs($this->buyer)->get(route('notifications'))->assertInertia(fn ($page) => $page->where('pushKey', null));

    $this->artisan('push:vapid --show')->expectsOutputToContain('VAPID_PUBLIC_KEY=B')->assertSuccessful();
});
