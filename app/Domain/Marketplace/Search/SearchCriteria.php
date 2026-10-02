<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Support\Regions;
use Illuminate\Http\Request;

/**
 * Marketplace filters, read from the query string (/cars?make=3&price_max=12000000…).
 * Prices are whole naira in the URL and kobo here.
 */
final class SearchCriteria
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'year_desc', 'mileage_asc', 'nearest'];

    public const RADII_KM = [5, 10, 25, 50, 100];

    public const PER_PAGE = 24;

    /**
     * Yes/no filters from what lots and their cars offer (?has[]=loans&has[]=inspected), with buyer-facing labels.
     * Lot settings (loans, trade-ins, offers, verified) and car details (negotiable, inspected, duty, registration).
     */
    public const EXTRAS = [
        'loans' => 'Car loans available',
        'trade_ins' => 'Takes trade-ins',
        'offers' => 'Open to offers',
        'negotiable' => 'Price negotiable',
        'inspected' => 'Inspected',
        'verified_lot' => 'Verified lot',
        'duty_paid' => 'Customs duty paid',
        'registered' => 'Registered in Nigeria',
    ];

    /** Most choices one list filter takes (keeps URLs and queries small). */
    private const MAX_CHOICES = 20;

    /**
     * @param  list<int>  $makeIds
     * @param  list<int>  $modelIds
     * @param  list<string>  $bodyTypes
     * @param  list<string>  $conditions
     * @param  list<string>  $fuels
     * @param  list<string>  $drivetrains
     * @param  list<string>  $colours  lower case
     * @param  list<int>  $featureIds  cars must have all of them
     * @param  list<string>  $extras  keys of EXTRAS
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $makeIds = [],
        public readonly array $modelIds = [],
        public readonly array $bodyTypes = [],
        public readonly array $conditions = [],
        public readonly ?string $transmission = null,
        public readonly array $fuels = [],
        public readonly array $drivetrains = [],
        public readonly array $colours = [],
        public readonly array $featureIds = [],
        public readonly array $extras = [],
        public readonly ?int $priceMin = null,
        public readonly ?int $priceMax = null,
        public readonly ?int $yearMin = null,
        public readonly ?int $yearMax = null,
        public readonly ?int $mileageMax = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?int $lotId = null,
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
        public readonly ?int $radiusKm = null,
        public readonly string $sort = 'newest',
        public readonly int $page = 1,
        public readonly int $perPage = self::PER_PAGE,
    ) {}

    public static function fromRequest(Request $request, ?int $lotId = null): self
    {
        $list = fn (string $key): array => array_slice(array_filter((array) $request->input($key, []), 'is_scalar'), 0, self::MAX_CHOICES);
        $ints = fn (string $key): array => array_values(array_unique(array_filter(array_map('intval', $list($key)), fn (int $v) => $v > 0)));
        $enums = fn (string $key, string $enum): array => array_values(array_unique(array_filter(
            array_map('strval', $list($key)),
            fn (string $v) => $enum::tryFrom($v) !== null,
        )));
        $colours = array_values(array_unique(array_filter(
            array_map(fn ($v) => mb_strtolower(trim((string) $v)), $list('colour')),
            fn (string $v) => $v !== '' && mb_strlen($v) <= 40,
        )));
        $extras = array_values(array_intersect(array_keys(self::EXTRAS), array_map('strval', $list('has'))));
        $int = fn (string $key): ?int => is_numeric($request->input($key)) && (int) $request->input($key) > 0 ? (int) $request->input($key) : null;
        $naira = fn (string $key): ?int => $int($key) !== null ? $int($key) * 100 : null;

        $lat = is_numeric($request->input('lat')) ? (float) $request->input('lat') : null;
        $lng = is_numeric($request->input('lng')) ? (float) $request->input('lng') : null;
        $hasLocation = $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180;

        $sort = in_array($request->input('sort'), self::SORTS, true) ? $request->input('sort') : ($hasLocation ? 'nearest' : 'newest');

        if ($sort === 'nearest' && ! $hasLocation) {
            $sort = 'newest';
        }

        $radius = $int('radius');

        return new self(
            query: filled($request->input('q')) ? mb_substr(trim((string) $request->input('q')), 0, 80) : null,
            makeIds: $ints('make'),
            modelIds: $ints('model'),
            bodyTypes: $enums('body', BodyType::class),
            conditions: $enums('condition', VehicleCondition::class),
            transmission: Transmission::tryFrom((string) $request->input('transmission'))?->value,
            fuels: $enums('fuel', FuelType::class),
            drivetrains: $enums('drive', Drivetrain::class),
            colours: $colours,
            featureIds: $ints('feature'),
            extras: $extras,
            priceMin: $naira('price_min'),
            priceMax: $naira('price_max'),
            yearMin: $int('year_min'),
            yearMax: $int('year_max'),
            mileageMax: $int('mileage_max'),
            city: filled($request->input('city')) ? mb_substr((string) $request->input('city'), 0, 80) : null,
            state: Regions::normalize(is_string($request->input('state')) ? $request->input('state') : null),
            lotId: $lotId,
            lat: $hasLocation ? $lat : null,
            lng: $hasLocation ? $lng : null,
            radiusKm: $hasLocation && in_array($radius, self::RADII_KM, true) ? $radius : null,
            sort: $sort,
            page: max(1, min(500, $int('page') ?? 1)),
        );
    }

    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** Filters as the frontend echoes them back (naira, not kobo). */
    public function toArray(): array
    {
        return [
            'q' => $this->query,
            'make' => $this->makeIds,
            'model' => $this->modelIds,
            'body' => $this->bodyTypes,
            'condition' => $this->conditions,
            'transmission' => $this->transmission,
            'fuel' => $this->fuels,
            'drive' => $this->drivetrains,
            'colour' => $this->colours,
            'feature' => $this->featureIds,
            'has' => $this->extras,
            'price_min' => $this->priceMin !== null ? intdiv($this->priceMin, 100) : null,
            'price_max' => $this->priceMax !== null ? intdiv($this->priceMax, 100) : null,
            'year_min' => $this->yearMin,
            'year_max' => $this->yearMax,
            'mileage_max' => $this->mileageMax,
            'city' => $this->city,
            'state' => $this->state,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'radius' => $this->radiusKm,
            'sort' => $this->sort,
        ];
    }

    /** Number of filters set, for the "Filters · 3" button. */
    public function activeFilterCount(): int
    {
        return count(array_filter([
            $this->makeIds, $this->modelIds, $this->bodyTypes, $this->conditions, $this->transmission, $this->fuels, $this->drivetrains,
            $this->colours, $this->priceMin || $this->priceMax, $this->yearMin || $this->yearMax, $this->mileageMax, $this->city,
            $this->state, $this->radiusKm,
        ])) + count($this->featureIds) + count($this->extras);
    }
}
