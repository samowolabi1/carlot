<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Enums\BodyType;
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
     * @param  list<int>  $makeIds
     * @param  list<string>  $bodyTypes
     * @param  list<string>  $conditions
     * @param  list<string>  $fuels
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $makeIds = [],
        public readonly ?int $modelId = null,
        public readonly array $bodyTypes = [],
        public readonly array $conditions = [],
        public readonly ?string $transmission = null,
        public readonly array $fuels = [],
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
        $ints = fn (string $key): array => array_values(array_filter(array_map('intval', (array) $request->input($key, [])), fn (int $v) => $v > 0));
        $enums = fn (string $key, string $enum): array => array_values(array_filter(
            array_map('strval', (array) $request->input($key, [])),
            fn (string $v) => $enum::tryFrom($v) !== null,
        ));
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
            modelId: $int('model'),
            bodyTypes: $enums('body', BodyType::class),
            conditions: $enums('condition', VehicleCondition::class),
            transmission: Transmission::tryFrom((string) $request->input('transmission'))?->value,
            fuels: $enums('fuel', FuelType::class),
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
            'model' => $this->modelId,
            'body' => $this->bodyTypes,
            'condition' => $this->conditions,
            'transmission' => $this->transmission,
            'fuel' => $this->fuels,
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
            $this->makeIds, $this->modelId, $this->bodyTypes, $this->conditions, $this->transmission, $this->fuels,
            $this->priceMin || $this->priceMax, $this->yearMin || $this->yearMax, $this->mileageMax, $this->city, $this->state, $this->radiusKm,
        ]));
    }
}
