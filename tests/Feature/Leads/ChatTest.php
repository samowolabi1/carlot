<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233', 'latitude' => 6.6, 'longitude' => 3.35]);
    $this->lot->update(['status' => 'active']);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->buyer = User::factory()->create(['name' => 'Chioma Okafor', 'phone' => '+2348035550001']);

    $this->start = function (array $data = []): Conversation {
        $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => $this->car->ulid, ...$data])->assertRedirect();

        return Conversation::query()->latest('id')->firstOrFail();
    };
});

it('starts a chat about a car, creating one lead and a customer book entry', function () {
    $conversation = ($this->start)(['body' => 'Is it still available?']);

    $lead = Lead::withoutGlobalScopes()->sole();
    expect($lead)
        ->source->toBe(LeadSource::Chat)
        ->stage->toBe(LeadStage::New)
        ->vehicle_id->toBe($this->car->id)
        ->customer_id->toBe($this->buyer->id)
        ->and($conversation->lead_id)->toBe($lead->id)
        ->and($conversation->messages()->sole()->body)->toBe('Is it still available?')
        ->and(LotCustomer::withoutGlobalScopes()->sole()->phone)->toBe('+2348035550001')
        ->and($this->owner->notifications()->sole()->data['kind'])->toBe('lead');

    // Tapping Chat again goes back to the same thread.
    expect(($this->start)()->id)->toBe($conversation->id)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);
});

it('sends guests through sign-in and back to the thread', function () {
    $this->get(route('conversations.start', ['vehicle' => $this->car->ulid]))->assertRedirect(route('login'));

    $this->actingAs($this->buyer)->get(route('conversations.start', ['vehicle' => $this->car->ulid]))
        ->assertRedirect(route('conversations.show', Conversation::sole()));
});

it('does not let staff start a chat with their own lot', function () {
    $this->actingAs($this->owner)->post(route('conversations.store'), ['vehicle' => $this->car->ulid])->assertForbidden();
    $this->actingAs($this->buyer)->post(route('conversations.store'), ['vehicle' => Vehicle::factory()->create()->ulid])->assertNotFound();
});

it('shows the thread to the buyer and keeps it private', function () {
    $conversation = ($this->start)(['body' => 'Hello']);

    $this->actingAs($this->buyer)->get(route('conversations.show', $conversation))
        ->assertInertia(fn (Assert $page) => $page->component('Chat/Show')->has('messages', 1));

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get(route('conversations.show', $conversation))->assertForbidden();
    $this->actingAs($stranger)->post(route('conversations.reply', $conversation), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($stranger)->getJson(route('conversations.poll', $conversation))->assertForbidden();

    // Lot staff read it from the lead page, not the buyer's view, but can poll it.
    $this->actingAs($this->owner)->post(route('conversations.reply', $conversation), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->owner)->getJson(route('conversations.poll', $conversation))->assertOk()->assertJsonCount(1, 'messages');
});

it('moves a new lead to contacted and assigns it when the seller first replies', function () {
    $conversation = ($this->start)(['body' => 'Hello']);
    $lead = Lead::withoutGlobalScopes()->sole();
    $this->travel(3)->minutes();

    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['body' => 'Yes, come and see it'])->assertRedirect();

    expect($lead->fresh())
        ->stage->toBe(LeadStage::Contacted)
        ->assigned_to->toBe($this->owner->id)
        ->first_response_at->not->toBeNull()
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->fresh()->unreadFor(Message::CUSTOMER))->toBe(1);

    // Polling returns only newer messages and marks them read.
    $first = $conversation->messages()->orderBy('id')->first();
    $this->actingAs($this->buyer)->getJson(route('conversations.poll', [$conversation, 'after' => $first->id]))
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.side', 'lot')
        ->assertJsonPath('messages.0.body', 'Yes, come and see it');
    expect($conversation->fresh()->unreadFor(Message::CUSTOMER))->toBe(0);
});

it('sends preset replies with the seller location and similar cars', function () {
    ($this->start)(['body' => 'Where are you?']);
    $lead = Lead::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['preset' => 'similar'])->assertSessionHasErrors('preset');
    Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'body_type' => $this->car->body_type, 'price' => $this->car->price]);

    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['preset' => 'location'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['preset' => 'similar'])->assertSessionHasNoErrors();

    $bodies = Message::query()->where('side', Message::LOT)->orderBy('id')->pluck('body');
    expect($bodies[0])->toContain('google.com/maps')
        ->and($bodies[1])->toStartWith('You might also like:')->toContain('/car/');
});

it('re-encodes chat photos and keeps them off the original upload', function () {
    Storage::fake(config('lotlink.media_disk'));
    $conversation = ($this->start)();

    $this->actingAs($this->buyer)->post(route('conversations.reply', $conversation), ['photo' => UploadedFile::fake()->image('car.jpg', 1200, 900)])->assertRedirect();

    $message = $conversation->messages()->sole();
    expect($message->attachment_path)->toEndWith('.webp');
    Storage::disk(config('lotlink.media_disk'))->assertExists($message->attachment_path);
});

it('counts unread messages for the buyer and the seller badge', function () {
    $conversation = ($this->start)(['body' => 'Hello']);
    $lead = Lead::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->get(route('dealer.leads.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('currentLot.leads_badge', 1)->where('columns.0.leads.0.unread', 1));

    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['body' => 'Hi Chioma']);

    $this->actingAs($this->buyer)->get(route('conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->component('Chat/Index')->where('unread.messages', 1)->where('conversations.0.unread', 1));

    $this->actingAs($this->buyer)->get(route('conversations.show', $conversation));
    $this->actingAs($this->buyer)->get(route('conversations.index'))->assertInertia(fn (Assert $page) => $page->where('unread.messages', 0));
});

it('texts the other side once when a message stays unread for 10 minutes', function () {
    $conversation = ($this->start)(['body' => 'Is the price negotiable?']);

    $this->artisan('chat:notify-unread');
    expect($this->whatsapp->to('+2348020000001', 'new_message'))->toBe([]);

    $this->travel(11)->minutes();
    $this->artisan('chat:notify-unread');
    $this->artisan('chat:notify-unread');

    $sent = $this->whatsapp->to('+2348020000001', 'new_message');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params)->toBe(['Chioma Okafor', 'Is the price negotiable?']);

    // The seller replies; the buyer doesn't read it.
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, Lead::withoutGlobalScopes()->sole()]), ['body' => 'A little']);
    $this->travel(11)->minutes();
    $this->artisan('chat:notify-unread');

    expect($this->whatsapp->to('+2348035550001', 'new_message'))->toHaveCount(1)
        ->and($this->whatsapp->to('+2348020000001', 'new_message'))->toHaveCount(1)
        ->and($conversation->fresh()->customer_notified_at)->not->toBeNull();
});

it('respects the buyer turning off message alerts', function () {
    $this->buyer->update(['notification_preferences' => ['messages' => ['phone' => false, 'mail' => false]]]);
    ($this->start)(['body' => 'Hello']);
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, Lead::withoutGlobalScopes()->sole()]), ['body' => 'Hi']);

    $this->travel(11)->minutes();
    $this->artisan('chat:notify-unread');

    expect($this->whatsapp->to('+2348035550001'))->toBe([])
        ->and($this->sms->sent)->toBe([]);
});

it('records WhatsApp and call taps as leads', function () {
    $this->actingAs($this->buyer)->postJson(route('leads.intent'), ['vehicle' => $this->car->ulid, 'source' => 'whatsapp'])->assertNoContent();
    $this->actingAs($this->buyer)->postJson(route('leads.intent'), ['vehicle' => $this->car->ulid, 'source' => 'call'])->assertNoContent();
    $this->actingAs($this->buyer)->postJson(route('leads.intent'), ['lot' => $this->lot->slug, 'source' => 'call'])->assertNoContent();

    $leads = Lead::withoutGlobalScopes()->orderBy('id')->get();
    expect($leads)->toHaveCount(2)
        ->and($leads[0]->source)->toBe(LeadSource::WhatsApp)
        ->and($leads[1]->vehicle_id)->toBeNull();

    // Staff tapping their own lot's buttons don't become leads.
    $this->actingAs($this->owner)->postJson(route('leads.intent'), ['vehicle' => $this->car->ulid, 'source' => 'call'])->assertNoContent();
    expect(Lead::withoutGlobalScopes()->count())->toBe(2);

    auth()->logout();
    $this->postJson(route('leads.intent'), ['vehicle' => $this->car->ulid, 'source' => 'call'])->assertUnauthorized();
});

it('authorises the private realtime channels', function () {
    $conversation = ($this->start)();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'key',
        'broadcasting.connections.reverb.secret' => 'secret',
        'broadcasting.connections.reverb.app_id' => 'app',
    ]);
    app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
    require base_path('routes/channels.php');

    $sales = User::factory()->staff()->create();
    LotMember::create(['lot_id' => $this->lot->id, 'user_id' => $sales->id, 'role' => LotRole::Sales, 'accepted_at' => now()]);

    $auth = fn (User $user, string $channel) => $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $auth($this->buyer, "private-conversation.{$conversation->ulid}")->assertOk();
    $auth($sales, "private-conversation.{$conversation->ulid}")->assertOk();
    $auth(User::factory()->create(), "private-conversation.{$conversation->ulid}")->assertForbidden();
    $auth($sales, "private-lot.{$this->lot->id}")->assertOk();
    $auth($this->buyer, "private-lot.{$this->lot->id}")->assertForbidden();
    $auth($this->buyer, "private-user.{$this->buyer->id}")->assertOk();
    $auth($this->buyer, "private-user.{$this->owner->id}")->assertForbidden();
});

it('lists each chat by its latest message and shows the thread oldest first', function () {
    $conversation = ($this->start)(['body' => 'First']);
    $this->actingAs($this->buyer)->post(route('conversations.reply', $conversation), ['body' => 'Second']);
    $this->actingAs($this->buyer)->post(route('conversations.reply', $conversation), ['body' => 'Third']);

    $this->get(route('conversations.index'))->assertInertia(fn (Assert $page) => $page->where('conversations.0.last', 'Third'));
    $this->get(route('conversations.show', $conversation))->assertInertia(fn (Assert $page) => $page
        ->where('messages.0.body', 'First')->where('messages.2.body', 'Third'));
});

it('sends the car\'s inspection report as a quick reply, or points to adding one', function () {
    Storage::fake('local');
    ($this->start)(['body' => 'Has it been inspected?']);
    $lead = Lead::withoutGlobalScopes()->sole();
    $send = fn () => $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, $lead]), ['preset' => 'inspection']);

    // No report yet: the button links to the inspection form, and sending explains why it can't.
    $this->actingAs($this->owner)->get(route('dealer.leads.show', [$this->lot, $lead]))
        ->assertInertia(fn (Assert $page) => $page->where('lead.inspection', null)
            ->where('lead.inspection_url', route('dealer.vehicles.inspection', [$this->lot, $this->car])));
    $send()->assertSessionHasErrors(['preset' => 'This car has no inspection report yet. Add one from Stock.']);

    $checklist = collect(InspectionChecklist::keys())->mapWithKeys(fn ($k) => [$k => ['status' => 'pass', 'note' => null]])->all();
    $this->actingAs($this->owner)->post(route('dealer.vehicles.inspection.store', [$this->lot, $this->car]), [
        'checklist' => array_replace($checklist, ['paint' => ['status' => 'advisory', 'note' => 'Stone chips']]),
        'summary' => 'Serviced last month.',
        'inspector_name' => 'Kemi (workshop)',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get(route('dealer.leads.show', [$this->lot, $lead]))
        ->assertInertia(fn (Assert $page) => $page->where('lead.inspection.score', 99));
    $send()->assertSessionHasNoErrors();

    $inspection = Inspection::withoutGlobalScopes()->sole();
    $body = Message::query()->where('side', Message::LOT)->latest('id')->value('body');
    expect($body)->toStartWith("Inspection report for the {$this->car->title()}: 99/100, inspected by Prime Motors on 5 Oct 2026.")
        ->toContain('Serviced last month.')
        ->toContain(route('inspections.pdf', $inspection));

    // The buyer can open the report from the link without signing in.
    auth()->logout();
    $this->get(route('inspections.pdf', $inspection))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
