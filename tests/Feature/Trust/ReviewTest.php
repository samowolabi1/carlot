<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Trust\Models\Review;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777']);

    $this->visit = fn (?User $buyer = null, array $attributes = []) => Appointment::factory()->at(now()->subHours(3))->create([
        'lot_id' => $this->lot->id, 'customer_id' => ($buyer ?? $this->buyer)->id, 'status' => 'completed',
        'checked_in_at' => now()->subHours(3), 'completed_at' => now()->subHours(2)->subMinute(), ...$attributes,
    ]);
    $this->review = fn (Appointment $a, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->buyer)
        ->post(route('reviews.store', $a), ['rating' => 4, 'tags' => ['friendly', 'on_time'], 'body' => 'Kemi had the car ready.', ...$data]);
});

it('invites the buyer 2 hours after a completed visit, once, with a link that works without signing in', function () {
    $appointment = ($this->visit)();
    ($this->visit)(null, ['completed_at' => now()->subHour()]); // too soon

    $this->artisan('reviews:invite')->expectsOutput('Sent 1 review invites.');
    $this->artisan('reviews:invite')->expectsOutput('Sent 0 review invites.');

    expect($this->whatsapp->to('+2348035550777', 'review_invite'))->toHaveCount(1)
        ->and($appointment->fresh()->review_invited_at)->not->toBeNull();

    $link = URL::signedRoute('reviews.edit', ['appointment' => $appointment->ulid], now()->addDays(30));
    $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Trust/Review')->where('author', 'Tunde A.')->where('reviewable', true));
    $this->get(route('reviews.edit', $appointment))->assertRedirect(route('login'));
});

it('posts a review, can edit it for 14 days, and shows the rating once there are 3', function () {
    $first = ($this->visit)();
    ($this->review)($first)->assertRedirect(route('bookings.index'))->assertSessionHas('success');

    $review = Review::withoutGlobalScopes()->sole();
    expect($review)->rating->toBe(4)->tags->toBe(['friendly', 'on_time'])
        ->and($this->lot->fresh())->reviews_count->toBe(1)->rating->toEqual(4.0)
        ->and($this->owner->notifications()->where('data->kind', 'review')->sole()->data['text'])->toContain('Tunde A. gave Prime Motors 4 stars');
    $this->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('lot.rating', null)->has('reviews', 1));

    ($this->review)($first, ['rating' => 5])->assertSessionHas('success', 'Review updated.');
    expect(Review::withoutGlobalScopes()->count())->toBe(1)->and($review->fresh()->edited_at)->not->toBeNull();

    foreach (['+2348035550778', '+2348035550779'] as $phone) {
        $other = User::factory()->create(['name' => 'Ada Obi', 'phone' => $phone]);
        ($this->review)(($this->visit)($other), ['rating' => 3], $other);
    }
    $this->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('lot.rating', 3.7)->where('lot.reviews_count', 3));

    $this->travel(15)->days();
    ($this->review)($first, ['rating' => 1])->assertSessionHasErrors('rating');
});

it('only reviews completed visits, by the buyer who booked', function () {
    $upcoming = Appointment::factory()->create(['lot_id' => $this->lot->id, 'customer_id' => $this->buyer->id, 'status' => 'confirmed']);
    ($this->review)($upcoming)->assertSessionHasErrors('rating');

    $visit = ($this->visit)();
    $stranger = User::factory()->create();
    ($this->review)($visit, [], $stranger)->assertForbidden();
    ($this->review)($visit, ['rating' => 6])->assertSessionHasErrors('rating');
    expect(Review::withoutGlobalScopes()->count())->toBe(0);
});

it('lets the seller reply once, and keeps replies to the seller\'s own team', function () {
    ($this->review)(($this->visit)());
    $review = Review::withoutGlobalScopes()->sole();

    $other = User::factory()->staff()->create();
    app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($other)->post(route('dealer.reviews.reply', [$this->lot, $review]), ['reply' => 'Hi'])->assertForbidden();

    $this->actingAs($this->owner)->get(route('dealer.reviews', $this->lot))->assertInertia(fn (Assert $page) => $page->component('Dealer/Reviews')->has('reviews.data', 1)->where('summary.count', 1));
    $this->actingAs($this->owner)->post(route('dealer.reviews.reply', [$this->lot, $review]), ['reply' => 'Thanks Tunde!'])->assertSessionHas('success');
    $this->actingAs($this->owner)->post(route('dealer.reviews.reply', [$this->lot, $review]), ['reply' => 'Again'])->assertSessionHasErrors('reply');

    expect($review->fresh()->reply)->toBe('Thanks Tunde!');
    $this->get(route('lots.show', $this->lot))->assertInertia(fn (Assert $page) => $page->where('reviews.0.reply', 'Thanks Tunde!'));

    // Another lot's reply route can't reach this seller's review (scoped bindings).
    $otherLot = $other->lots()->first();
    $this->actingAs($other)->post(route('dealer.reviews.reply', [$otherLot, $review]), ['reply' => 'Hi'])->assertNotFound();
});

it('shows a "Leave a review" link on past bookings', function () {
    $visit = ($this->visit)();
    $this->actingAs($this->buyer)->get(route('bookings.index'))->assertInertia(fn (Assert $page) => $page->where('past.0.review.rating', null)->where('past.0.ulid', $visit->ulid));
});
