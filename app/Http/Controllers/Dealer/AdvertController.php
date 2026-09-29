<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Advertising\Actions\CreateAdCampaign;
use App\Domain\Advertising\Enums\AdCta;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Support\AdImage;
use App\Domain\Advertising\Support\AdSchedule;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Support\SpotlightPricing;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Marketplace\SearchController;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Advertise (paid to LotLink): homepage and search banners, plus links to car spotlights and featured lots. Owners and managers. */
class AdvertController extends Controller
{
    public function index(Lot $lot): Response
    {
        Gate::authorize('buySpotlight', $lot);
        $tz = $lot->timezone;

        return Inertia::render('Dealer/Advertise/Index', [
            'campaigns' => AdCampaign::query()->with(['vehicle.make', 'vehicle.model', 'vehicle.cover'])->latest('id')->limit(50)->get()
                ->map(fn (AdCampaign $c) => [
                    'ulid' => $c->ulid,
                    'placement' => $c->placement->label(),
                    'headline' => $c->headline,
                    'image' => $c->imageUrl(),
                    'state' => $c->state(),
                    'state_label' => AdCampaign::stateLabel($c->state()),
                    'dates' => $c->starts_at
                        ? $c->starts_at->copy()->setTimezone($tz)->format('j M').' – '.$c->ends_at?->copy()->setTimezone($tz)->format('j M Y')
                        : 'From '.$c->requested_start->format('j M Y').' · '.$c->days.' days',
                    'price' => $c->money(),
                    'impressions' => $c->impressions,
                    'clicks' => $c->clicks,
                    'ctr' => $c->ctr(),
                    'note' => $c->review_note,
                ]),
            'products' => self::products($lot),
            'live' => $lot->status === LotStatus::Active,
        ]);
    }

    public function create(Request $request, Lot $lot): Response
    {
        Gate::authorize('buySpotlight', $lot);
        $placement = AdPlacement::tryFrom((string) $request->query('placement')) ?? AdPlacement::HomeBanner;
        $today = AdSchedule::today();

        return Inertia::render('Dealer/Advertise/Create', [
            'placement' => $placement->value,
            'placements' => collect(AdPlacement::cases())->map(fn (AdPlacement $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'description' => $p->description(),
                'size' => $p->size(),
                'targetable' => $p->targetable(),
                'options' => AdSchedule::options($p),
                // The first date with a free slot for a week-long run, to show before they pick.
                'next_free' => AdSchedule::nextStart($p, $today, 7)->setTimezone((string) config('lotlink.timezone'))->toDateString(),
            ]),
            'ctas' => AdCta::options(),
            'cars' => Vehicle::query()->marketplace()->where('vehicles.lot_id', $lot->id)->with(['make', 'model', 'cover', 'lot'])->latest('listed_at')->limit(100)->get()
                ->map(fn (Vehicle $v) => ['value' => $v->ulid, 'label' => "{$v->title()} · {$v->formattedPrice()}", 'image' => MarketplacePresenter::image($v->cover)['full'] ?? null]),
            'makes' => SearchController::filterOptions()['makes'],
            'bodyTypes' => BodyType::options(),
            'minDate' => $today->copy()->setTimezone((string) config('lotlink.timezone'))->toDateString(),
            'maxDate' => $today->copy()->addDays(AdCampaign::BOOK_AHEAD_DAYS)->setTimezone((string) config('lotlink.timezone'))->toDateString(),
            'maxMb' => intdiv(AdImage::MAX_KB, 1024),
            'lot' => ['name' => $lot->name, 'logo_url' => $lot->logo_url, 'initials' => $lot->initials(), 'brand_color' => $lot->brand_color],
        ]);
    }

    public function store(Request $request, Lot $lot, CreateAdCampaign $create): HttpResponse
    {
        Gate::authorize('buySpotlight', $lot);

        $data = $request->validate([
            'placement' => ['required', Rule::enum(AdPlacement::class)],
            'days' => ['required', 'integer', Rule::in(AdCampaign::DAYS)],
            'start' => ['required', 'date_format:Y-m-d'],
            'headline' => ['required', 'string', 'min:4', 'max:60'],
            'subtext' => ['nullable', 'string', 'max:120'],
            'cta' => ['required', Rule::enum(AdCta::class)],
            'vehicle' => ['nullable', 'string', 'size:26'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.AdImage::MAX_KB, 'dimensions:min_width=800,min_height=200'],
            'make_id' => ['nullable', 'integer', 'exists:makes,id'],
            'body_type' => ['nullable', Rule::enum(BodyType::class)],
            'city' => ['nullable', 'string', 'max:60'],
        ], [
            'image.dimensions' => 'Use an image at least 800 pixels wide so the banner looks sharp.',
        ]);

        $result = $create->run($lot, $request->user(), $data, $request->file('image'));

        return Inertia::location($result['checkout']);
    }

    /** @return list<array<string, mixed>> every way to promote on LotLink, with prices (whole naira) */
    private static function products(Lot $lot): array
    {
        $from = fn (array $options) => $options[0]['price'] ?? null;

        return [
            ['key' => 'home_banner', 'label' => AdPlacement::HomeBanner->label(), 'description' => AdPlacement::HomeBanner->description(), 'from' => $from(AdSchedule::options(AdPlacement::HomeBanner)), 'url' => route('dealer.ads.create', [$lot, 'placement' => 'home_banner'])],
            ['key' => 'search_banner', 'label' => AdPlacement::SearchBanner->label(), 'description' => AdPlacement::SearchBanner->description(), 'from' => $from(AdSchedule::options(AdPlacement::SearchBanner)), 'url' => route('dealer.ads.create', [$lot, 'placement' => 'search_banner'])],
            ['key' => 'spotlight', 'label' => 'Car spotlight', 'description' => 'One car first in matching searches ("Sponsored") and in the home page Spotlight row.', 'from' => $from(SpotlightPricing::options(SpotlightPlacement::Car)), 'url' => route('dealer.vehicles.index', $lot)],
            ['key' => 'featured', 'label' => 'Featured lot', 'description' => 'Your lot in the "Featured lots" row on the home page.', 'from' => $from(SpotlightPricing::options(SpotlightPlacement::FeaturedLot)), 'url' => route('dealer.billing', $lot).'#featured-heading'],
        ];
    }
}
