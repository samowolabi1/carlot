<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'plan_id' => Plan::where('code', 'pro')->value('id')]);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_250_000_000, 'negotiable' => true]); // ₦12.5m
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550001']);

    $this->offer = fn (string $amount = '11,800,000', ?User $as = null) => $this->actingAs($as ?? $this->buyer)
        ->post(route('offers.store', $this->car->ulid), ['amount' => $amount, 'message' => 'I can pay this week']);
});

it('shows the offer sheet with similar listings', function () {
    Vehicle::factory()->count(3)->available()->create(['lot_id' => $this->lot->id, 'vehicle_model_id' => $this->car->vehicle_model_id, 'make_id' => $this->car->make_id, 'year' => $this->car->year, 'price' => 1_200_000_000]);

    $this->actingAs($this->buyer)->get(route('offers.create', $this->car->ulid))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Deals/MakeOffer')
            ->where('price', 12_500_000)
            ->where('market.count', 4));

    $this->get(route('cars.show', substr($this->car->publicPath(), 5)))
        ->assertInertia(fn (Assert $page) => $page->where('deals.offers', true));
});

it('takes an offer, captures a lead and tells the seller', function () {
    ($this->offer)()->assertRedirect(route('bookings.index').'#offers');

    $offer = Offer::withoutGlobalScopes()->sole();
    expect($offer)
        ->amount->toBe(1_180_000_000)
        ->status->toBe(OfferStatus::Pending)
        ->message->toBe('I can pay this week')
        ->expires_at->toIso8601String()->toBe('2026-10-07T09:00:00+00:00')
        ->and(Lead::withoutGlobalScopes()->sole()->source)->toBe(LeadSource::Offer)
        ->and(Conversation::sole()->messages()->where('side', Message::SYSTEM)->sole()->body)->toBe('Offer: ₦11,800,000 (−5.6%) · “I can pay this week”')
        ->and($this->owner->notifications()->where('data->kind', 'offer')->sole()->data['text'])->toContain('Tunde A. offered ₦11,800,000');
});

it('keeps offers between half and the full asking price', function () {
    ($this->offer)('6,000,000')->assertSessionHasErrors(['amount' => 'Offers must be at least ₦6,250,000 (half the asking price).']);
    ($this->offer)('13,000,000')->assertSessionHasErrors('amount');
    ($this->offer)('6,250,000')->assertSessionHasNoErrors();
});

it('only takes offers where the seller and the car allow them', function () {
    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    ($this->offer)()->assertSessionHasErrors(['amount' => 'This car is not taking offers.']);
    $this->actingAs($this->buyer)->get(route('offers.create', $this->car->ulid))->assertNotFound();

    $this->lot->update(['plan_id' => Plan::where('code', 'pro')->value('id'), 'accepts_offers' => false]);
    ($this->offer)()->assertSessionHasErrors('amount');

    $this->lot->update(['accepts_offers' => true]);
    $this->car->update(['negotiable' => false]);
    ($this->offer)()->assertSessionHasErrors('amount');

    $this->car->update(['negotiable' => true]);
    ($this->offer)('11,800,000', $this->owner)->assertSessionHasErrors(['amount' => 'You work for this seller.']);
});

it('replaces the buyer\'s open offer with a new one', function () {
    ($this->offer)();
    ($this->offer)('12,000,000');

    expect(Offer::withoutGlobalScopes()->orderBy('id')->pluck('status')->all())->toBe([OfferStatus::Withdrawn, OfferStatus::Pending]);
});

it('lets the seller accept, which moves the lead to negotiating and tells the buyer', function () {
    ($this->offer)();
    $offer = Offer::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'accept'])->assertSessionHasNoErrors();

    expect($offer->fresh())->status->toBe(OfferStatus::Accepted)->responded_by->toBe($this->owner->id)
        ->and($offer->fresh()->agreedAmount())->toBe(1_180_000_000)
        ->and(Lead::withoutGlobalScopes()->sole()->stage)->toBe(LeadStage::Negotiating)
        ->and(AuditLog::where('action', 'offer.accept')->count())->toBe(1);

    $sent = $this->whatsapp->to('+2348035550001', 'offer_update');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params[0])->toBe('Tunde')
        ->and($sent[0]->params[3])->toBe('They accepted your offer of ₦11,800,000. Book a visit to complete the deal.');

    // Only waiting offers can be answered.
    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'decline'])->assertSessionHasErrors('offer');
});

it('runs the counter-offer flow', function () {
    ($this->offer)();
    $offer = Offer::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'counter', 'counter_amount' => '11,000,000'])
        ->assertSessionHasErrors('counter_amount');
    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'counter', 'counter_amount' => '₦12,100,000', 'message' => 'Includes a service'])
        ->assertSessionHasNoErrors();

    $this->travel(10)->hours();
    expect($offer->fresh())->status->toBe(OfferStatus::Countered)->counter_amount->toBe(1_210_000_000)
        ->and($this->whatsapp->to('+2348035550001', 'offer_update')[0]->params[3])->toContain('They countered with ₦12,100,000');

    $this->actingAs($this->buyer)->get(route('bookings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('offers.0.status', 'countered')->where('offers.0.counter', '₦12,100,000')->where('offers.0.left', '38 h left'));

    // Only the buyer can answer the counter.
    $this->actingAs(User::factory()->create())->post(route('offers.accept', $offer))->assertForbidden();
    $this->actingAs($this->buyer)->post(route('offers.accept', $offer))->assertSessionHasNoErrors();

    expect($offer->fresh())->status->toBe(OfferStatus::Accepted)
        ->and($offer->fresh()->agreedAmount())->toBe(1_210_000_000)
        ->and($this->owner->notifications()->where('data->kind', 'offer')->latest()->first()->data['text'])->toContain('accepted your counter-offer of ₦12,100,000');
});

it('lets the buyer decline a counter', function () {
    ($this->offer)();
    $offer = Offer::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'counter', 'counter_amount' => '12,100,000']);

    $this->actingAs($this->buyer)->post(route('offers.decline', $offer))->assertSessionHasNoErrors();
    expect($offer->fresh()->status)->toBe(OfferStatus::Declined);
});

it('expires offers nobody answered after 48 hours', function () {
    ($this->offer)();
    $this->travel(47)->hours();
    $this->artisan('offers:expire');
    expect(Offer::withoutGlobalScopes()->sole()->status)->toBe(OfferStatus::Pending);

    $this->travel(2)->hours();
    $this->artisan('offers:expire');
    expect(Offer::withoutGlobalScopes()->sole()->status)->toBe(OfferStatus::Expired)
        ->and($this->whatsapp->to('+2348035550001', 'offer_update')[0]->params[3])->toContain('expired without a reply');

    $offer = Offer::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->post(route('dealer.offers.respond', [$this->lot, $offer]), ['action' => 'accept'])->assertSessionHasErrors('offer');
});

it('shows offers on the seller\'s page with a nudge for old stock', function () {
    $this->car->forceFill(['listed_at' => now()->subDays(92)])->save();
    ($this->offer)();

    $this->actingAs($this->owner)->get(route('dealer.offers.index', $this->lot))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dealer/Deals')
            ->where('offers.0.buyer', 'Tunde A.')
            ->where('offers.0.off', '−5.6%')
            ->where('offers.0.left', '48 h left')
            ->where('offers.0.hint', fn (string $hint) => str_contains($hint, 'listed 92 days with no other leads'))
            ->where('currentLot.deals_badge', 1));
});
