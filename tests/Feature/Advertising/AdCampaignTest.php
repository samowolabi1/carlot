<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Actions\ReviewAdCampaign;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Notifications\AdReviewed;
use App\Domain\Advertising\Notifications\AdSubmitted;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Make;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Filament\Resources\AdCampaignResource\Pages\ListAdCampaigns;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    Storage::fake(config('lotlink.media_disk'));
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC')); // Mon 10:00 Lagos
    $this->owner = User::factory()->staff()->create(['email' => 'owner@example.com']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'city' => 'Ikeja']);
    $this->lot->update(['status' => 'active']);
    $this->camry = $this->car($this->lot, 'Toyota', 'Camry', ['price' => 1_250_000_000]);
    $this->admin = User::factory()->admin()->create(['email' => 'ops@lotlink.test']);

    $this->book = fn (array $data = [], ?User $as = null, $lot = null) => $this->actingAs($as ?? $this->owner)->post(route('dealer.ads.store', $lot ?? $this->lot), [
        'placement' => 'home_banner',
        'days' => 7,
        'start' => '2026-10-05',
        'headline' => 'December deals on Toyota SUVs',
        'subtext' => 'Foreign-used, duty paid, inspected.',
        'cta' => 'see_cars',
        'image' => UploadedFile::fake()->image('banner.jpg', 1800, 700),
        ...$data,
    ], ['X-Inertia' => 'true']);
    $this->pay = fn () => $this->actingAs($this->owner)->get(route('dealer.billing.callback', ['lot' => $this->lot, 'reference' => $this->payments->lastReference()]));
    $this->approve = fn (AdCampaign $c) => app(ReviewAdCampaign::class)->approve($c, $this->admin);
});

it('books a homepage banner: paid to LotLink, then checked before it runs', function () {
    Notification::fake();

    ($this->book)()->assertStatus(409); // Inertia::location to the checkout

    $campaign = AdCampaign::withoutGlobalScopes()->sole();
    $payment = Payment::sole();
    expect($campaign)->status->toBe(AdStatus::Draft)->price->toBe(4_000_000)
        ->and($payment)->purpose->toBe(PaymentPurpose::Advert)->amount->toBe(4_000_000)
        ->and($payment->description)->toBe('Homepage banner · 7 days');

    // The creative is cropped to the banner size and re-encoded.
    [$w, $h] = getimagesizefromstring(Storage::disk(config('lotlink.media_disk'))->get($campaign->image_path));
    expect([$w, $h])->toBe([1600, 600]);

    ($this->pay)()->assertRedirect(route('dealer.ads.index', $this->lot));
    expect($campaign->fresh()->status)->toBe(AdStatus::InReview);
    Notification::assertSentTo($this->admin, AdSubmitted::class);

    // Not on the home page until approved.
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('banners', 0));

    ($this->approve)($campaign);
    expect($campaign->fresh())->status->toBe(AdStatus::Approved)
        ->starts_at->toIso8601String()->toBe('2026-10-05T09:00:00+00:00') // asked-for day already started: from now, no days lost
        ->ends_at->toIso8601String()->toBe('2026-10-12T09:00:00+00:00');
    Notification::assertSentTo($this->owner, AdReviewed::class, fn ($n) => $n->event === 'approved');

    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('banners', 1)
        ->where('banners.0.headline', 'December deals on Toyota SUVs')
        ->where('banners.0.lot', 'Prime Motors')
        ->missing('banners.0.id'));

    // It's a lot payment: on the Billing page and in the invoices.
    $this->actingAs($this->owner)->get(route('dealer.billing', $this->lot))->assertInertia(fn (Assert $page) => $page->has('payments', 1));

    // Stops showing when the week is up.
    $this->travel(8)->days();
    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('banners', 0));
});

it('counts views and clicks once per visit, not for bots or the lot\'s own staff', function () {
    ($this->book)(['vehicle' => $this->camry->ulid, 'image' => null, 'cta' => 'view_car']);
    ($this->pay)();
    $campaign = AdCampaign::withoutGlobalScopes()->sole();
    ($this->approve)($campaign);
    expect($campaign->fresh()->imageUrl())->toContain('-1600.webp'); // the car's photo

    auth()->logout();
    $this->post(route('ads.seen', $campaign->ulid))->assertNoContent();
    $this->post(route('ads.seen', $campaign->ulid))->assertNoContent();
    $this->get(route('ads.click', $campaign->ulid))->assertRedirect($this->camry->publicPath());
    $this->get(route('ads.click', $campaign->ulid));
    $this->withHeader('User-Agent', 'Googlebot/2.1')->post(route('ads.seen', $campaign->ulid));
    $this->flushSession();
    $this->actingAs($this->owner)->post(route('ads.seen', $campaign->ulid));

    expect($campaign->fresh())->impressions->toBe(1)->clicks->toBe(1);

    $this->actingAs($this->owner)->get(route('dealer.ads.index', $this->lot))->assertInertia(fn (Assert $page) => $page
        ->component('Dealer/Advertise/Index')
        ->where('campaigns.0.state', 'live')
        ->where('campaigns.0.ctr', 100));
});

it('refunds and tells the lot when an advert is rejected', function () {
    Notification::fake();
    ($this->book)();
    ($this->pay)();
    $campaign = AdCampaign::withoutGlobalScopes()->sole();

    app(ReviewAdCampaign::class)->reject($campaign, $this->admin, 'Image shows another lot\'s logo');

    expect($campaign->fresh())->status->toBe(AdStatus::Rejected)->review_note->toBe('Image shows another lot\'s logo')
        ->and(Payment::sole()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->payments->refunded)->toHaveCount(1);
    Notification::assertSentTo($this->owner, AdReviewed::class, fn ($n) => $n->event === 'rejected' && str_contains($n->toArray($this->owner)['text'], 'refunded'));
});

it('limits how many run at once and offers the next free start', function () {
    config(['lotlink.adverts.home_banner.slots' => 1]);
    ($this->book)();
    ($this->pay)();

    $second = User::factory()->staff()->create();
    $other = app(CreateLot::class)->run($second, ['name' => 'Other Autos']);
    $other->update(['status' => 'active']);

    ($this->book)([], $second, $other)->assertSessionHasErrors(['start' => 'All Homepage banner slots are taken then. The next free start is Mon 12 Oct.']);
    ($this->book)(['start' => '2026-10-12'], $second, $other)->assertStatus(409);

    // Approving a late check still fits the dates in: a free slot is found.
    $this->travel(1)->day();
    $first = AdCampaign::withoutGlobalScopes()->where('lot_id', $this->lot->id)->sole();
    ($this->approve)($first);
    expect($first->fresh()->starts_at->toIso8601String())->toBe('2026-10-06T09:00:00+00:00');
});

it('shows the search banner that fits the search', function () {
    $toyota = Make::where('name', 'Toyota')->value('id');
    ($this->book)(['placement' => 'search_banner', 'headline' => 'Every Toyota in Ikeja', 'make_id' => $toyota, 'image' => UploadedFile::fake()->image('b.jpg', 1400, 400)]);
    ($this->pay)();
    ($this->approve)(AdCampaign::withoutGlobalScopes()->sole());

    $honda = $this->car($this->lot, 'Honda', 'Accord');
    auth()->logout();

    $this->get(route('cars.index', ['make' => [$toyota]]))->assertInertia(fn (Assert $page) => $page->where('banner.headline', 'Every Toyota in Ikeja'));
    $this->get(route('cars.index', ['make' => [$honda->make_id]]))->assertInertia(fn (Assert $page) => $page->where('banner', null));
    $this->get(route('cars.index'))->assertInertia(fn (Assert $page) => $page->where('banner', null));
});

it('keeps booking to owners and managers, and each lot sees only its own adverts', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->get(route('dealer.ads.index', $this->lot))->assertForbidden();
    ($this->book)([], $sales)->assertForbidden();

    ($this->book)();
    $second = User::factory()->staff()->create();
    $other = app(CreateLot::class)->run($second, ['name' => 'Other Autos']);
    $this->actingAs($second)->get(route('dealer.ads.index', $other))->assertInertia(fn (Assert $page) => $page->has('campaigns', 0));

    // A car from another lot can't be advertised.
    ($this->book)(['vehicle' => $this->camry->ulid], $second, $other)->assertSessionHasErrors();
});

it('lets admins review adverts in /admin and take one down', function () {
    ($this->book)();
    ($this->pay)();
    $campaign = AdCampaign::withoutGlobalScopes()->sole();

    $this->actingAs($this->admin);
    Livewire::test(ListAdCampaigns::class)->assertCanSeeTableRecords([$campaign])->callTableAction('approve', $campaign);
    expect($campaign->fresh()->status)->toBe(AdStatus::Approved);

    Livewire::test(ListAdCampaigns::class)->filterTable('status', AdStatus::Approved->value)
        ->callTableAction('remove', $campaign->refresh(), ['note' => 'Complaints about the offer'])->assertHasNoTableActionErrors();
    expect($campaign->fresh()->status)->toBe(AdStatus::Removed);

    auth()->logout();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('banners', 0));
});
