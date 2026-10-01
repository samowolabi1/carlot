<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Support\AdSchedule;
use App\Domain\Advertising\Support\AdvertPricing;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Support\SpotlightPricing;
use App\Domain\Lots\Actions\CreateLot;
use App\Filament\Pages\AdvertPriceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => AdvertPricing::flush());

function newPrices(): array
{
    return [
        'home_banner' => ['p7' => 50000, 'p14' => 90000, 'p30' => 170000, 'slots' => 3],
        'search_banner' => ['p7' => 25000, 'p14' => 45000, 'p30' => 80000, 'slots' => 8],
        'car' => ['p7' => 6000, 'p14' => 11000, 'p30' => 20000],
        'featured_lot' => ['p7' => 16000, 'p14' => 29000, 'p30' => 52000],
    ];
}

it('is for admins only', function () {
    $this->get('/admin/settings/advert-prices')->assertRedirect();
    $this->actingAs(User::factory()->staff()->create())->get('/admin/settings/advert-prices')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/settings/advert-prices')->assertOk()->assertSee('Homepage banner')->assertSee('Car spotlight');
});

it('lets an admin change banner and spotlight prices and banner slots', function () {
    $this->actingAs($this->admin);

    Livewire::test(AdvertPriceSettings::class)
        ->assertFormSet(['home_banner.p7' => 40000, 'home_banner.slots' => 5, 'car.p7' => 5000])
        ->fillForm(newPrices())
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AdSchedule::price(AdPlacement::HomeBanner, 7))->toBe(5_000_000)
        ->and(AdSchedule::price(AdPlacement::SearchBanner, 30))->toBe(8_000_000)
        ->and(AdPlacement::HomeBanner->slots())->toBe(3)
        ->and(AdPlacement::SearchBanner->slots())->toBe(8)
        ->and(SpotlightPricing::price(SpotlightPlacement::Car, 14))->toBe(1_100_000)
        ->and(SpotlightPricing::price(SpotlightPlacement::FeaturedLot, 7))->toBe(1_600_000)
        ->and(AuditLog::where('action', 'admin.advert_prices_changed')->exists())->toBeTrue();

    // A fresh process (the next request or a queue worker) picks them up.
    config(['lotlink.adverts' => AdvertPricing::defaults()['adverts'], 'lotlink.billing.spotlight' => AdvertPricing::defaults()['spotlight']]);
    AdvertPricing::apply();
    expect(AdSchedule::price(AdPlacement::HomeBanner, 7))->toBe(5_000_000);
});

it('charges new bookings the new price and keeps what was already booked', function () {
    Storage::fake(config('lotlink.media_disk'));
    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $lot->update(['status' => 'active']);
    $book = fn () => $this->actingAs($owner)->post(route('dealer.ads.store', $lot), [
        'placement' => 'home_banner', 'days' => 7, 'start' => now('Africa/Lagos')->toDateString(), 'headline' => 'December deals',
        'cta' => 'see_cars', 'image' => UploadedFile::fake()->image('b.jpg', 1800, 700),
    ], ['X-Inertia' => 'true']);

    $book();
    AdvertPricing::save(AdvertPriceSettings::fromForm(newPrices()), $this->admin);
    $book();

    expect(AdCampaign::withoutGlobalScopes()->orderBy('id')->pluck('price')->all())->toBe([4_000_000, 5_000_000]);

    // The dealer's page shows the new prices.
    $this->actingAs($owner)->get(route('dealer.ads.create', $lot))->assertInertia(fn ($page) => $page->where('placements.0.options.0.price', 50000));
});

it('checks the numbers and resets to the defaults', function () {
    $this->actingAs($this->admin);

    Livewire::test(AdvertPriceSettings::class)
        ->fillForm(['home_banner' => ['p7' => 0, 'p14' => 90000, 'p30' => 170000, 'slots' => 0]])
        ->call('save')
        ->assertHasFormErrors(['home_banner.p7', 'home_banner.slots']);
    expect(AdvertPricing::overrides())->toBe([]);

    AdvertPricing::save(AdvertPriceSettings::fromForm(newPrices()), $this->admin);
    Livewire::test(AdvertPriceSettings::class)->callAction('reset');

    expect(AdSchedule::price(AdPlacement::HomeBanner, 7))->toBe(4_000_000)
        ->and(SpotlightPricing::price(SpotlightPlacement::Car, 7))->toBe(500_000)
        ->and(AdPlacement::HomeBanner->slots())->toBe(5);
});
