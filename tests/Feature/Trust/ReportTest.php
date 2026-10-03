<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Trust\Actions\ModerateListing;
use App\Domain\Trust\Actions\ModerateReview;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000]);
    $this->admin = User::factory()->admin()->create();
    $this->buyers = collect(range(1, 3))->map(fn ($i) => User::factory()->create(['phone' => '+23480355500'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]));
    $this->report = fn (User $as, string $kind, string $id, string $reason = 'scam') => $this->actingAs($as)->post(route('reports.store'), ['kind' => $kind, 'id' => $id, 'reason' => $reason, 'details' => 'Asked me to pay before viewing']);
});

it('takes a listing off the marketplace after 3 open reports until an admin looks', function () {
    ($this->report)($this->buyers[0], 'vehicle', $this->car->ulid)->assertSessionHas('success');
    ($this->report)($this->buyers[0], 'vehicle', $this->car->ulid)->assertSessionHasErrors('reason'); // once per person
    ($this->report)($this->buyers[1], 'vehicle', $this->car->ulid);
    expect($this->car->fresh()->isHeld())->toBeFalse();

    ($this->report)($this->buyers[2], 'vehicle', $this->car->ulid, 'misleading');
    $car = $this->car->fresh();
    expect($car->isHeld())->toBeTrue()->and(Vehicle::query()->marketplace()->count())->toBe(0);
    $this->get($car->publicPath())->assertNotFound();

    // The seller sees why, and can't put it back on sale itself.
    $this->actingAs($this->owner)->get(route('dealer.vehicles.index', $this->lot))->assertInertia(fn (Assert $page) => $page->where('vehicles.data.0.held', 'Reported by buyers: CarYard is taking a look')->where('vehicles.data.0.next_statuses', []));
    $this->actingAs($this->owner)->patch(route('dealer.vehicles.status', [$this->lot, $car]), ['status' => 'hidden']);
    $this->actingAs($this->owner)->patch(route('dealer.vehicles.status', [$this->lot, $car->fresh()]), ['status' => 'available'])->assertSessionHasErrors();

    app(ModerateListing::class)->approve($car->fresh()->forceFill(['status' => 'available']), $this->admin);
    expect($car->fresh()->isHeld())->toBeFalse()
        ->and(Report::where('status', 'dismissed')->count())->toBe(3)
        ->and($this->owner->notifications()->where('data->kind', 'moderation')->count())->toBe(1);
});

it('lets admins hide a reported listing from the queue', function () {
    ($this->report)($this->buyers[0], 'vehicle', $this->car->ulid);
    $report = Report::sole();

    $this->actingAs($this->admin);
    Livewire::test(ListReports::class)->assertCanSeeTableRecords([$report])->callTableAction('hide', $report, ['reason' => 'Payment before viewing']);

    expect($this->car->fresh())->held_reason->toBe('Hidden by CarYard: Payment before viewing')
        ->and($report->fresh()->status->value)->toBe('actioned');
});

it('hides a review a buyer reports, but not one the seller reports about itself', function () {
    $author = $this->buyers[0];
    $visit = Appointment::factory()->at(now()->subDay())->create(['lot_id' => $this->lot->id, 'customer_id' => $author->id, 'status' => 'completed', 'completed_at' => now()->subDay()]);
    $this->actingAs($author)->post(route('reviews.store', $visit), ['rating' => 1, 'body' => 'Rude staff']);
    $review = Review::withoutGlobalScopes()->sole();

    ($this->report)($this->owner, 'review', $review->ulid, 'misleading')->assertSessionHas('success');
    expect($review->fresh()->status->value)->toBe('visible');

    ($this->report)($this->buyers[1], 'review', $review->ulid, 'offensive');
    expect($review->fresh()->status->value)->toBe('hidden')->and($this->lot->fresh()->reviews_count)->toBe(0);

    app(ModerateReview::class)->restore($review, $this->admin);
    expect($review->fresh()->status->value)->toBe('visible')->and($this->lot->fresh()->reviews_count)->toBe(1)
        ->and(Report::where('status', 'open')->count())->toBe(0);
});

it('only takes reports on things the reporter can see, and not on their own lot', function () {
    ($this->report)($this->owner, 'vehicle', $this->car->ulid)->assertSessionHasErrors('reason');
    ($this->report)($this->owner, 'lot', $this->lot->slug)->assertSessionHasErrors('reason');
    ($this->report)($this->buyers[0], 'vehicle', Vehicle::factory()->create()->ulid)->assertNotFound(); // a draft
    ($this->report)($this->buyers[0], 'lot', $this->lot->slug, 'offensive')->assertSessionHas('success');
    auth()->logout();
    $this->post(route('reports.store'), ['kind' => 'lot', 'id' => $this->lot->slug, 'reason' => 'scam'])->assertRedirect(route('login'));

    // Chat messages: only people in the conversation, and not their own words.
    $this->actingAs($this->buyers[0])->post(route('conversations.store'), ['vehicle' => $this->car->ulid, 'body' => 'Hello']);
    $conversation = Conversation::sole();
    $this->actingAs($this->owner)->post(route('dealer.leads.messages.store', [$this->lot, Lead::withoutGlobalScopes()->sole()]), ['body' => 'Send ₦50k to hold it']);
    $lotMessage = $conversation->messages()->where('side', 'lot')->sole();
    $buyerMessage = $conversation->messages()->where('side', 'customer')->sole();

    ($this->report)($this->buyers[1], 'message', (string) $lotMessage->id)->assertNotFound();
    ($this->report)($this->buyers[0], 'message', (string) $buyerMessage->id)->assertSessionHasErrors('reason');
    ($this->report)($this->buyers[0], 'message', (string) $lotMessage->id)->assertSessionHas('success');
    expect(Report::where('reportable_type', Message::class)->sole()->lot_id)->toBe($this->lot->id);
});
