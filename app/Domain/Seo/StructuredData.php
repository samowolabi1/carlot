<?php

namespace App\Domain\Seo;

use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;

/**
 * schema.org JSON-LD (TDD M18): Car + Offer on car pages, AutoDealer on lot pages. Only
 * buyer-facing data: no full VIN, no costs.
 */
final class StructuredData
{
    /** @return array<string, mixed> */
    public static function car(Vehicle $v): array
    {
        $v->loadMissing(['make', 'model', 'lot', 'media']);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Car',
            'name' => $v->title(),
            'url' => url($v->publicPath()),
            'description' => $v->description ? mb_substr($v->description, 0, 500) : null,
            'image' => $v->media->map(fn (VehicleMedia $m) => $m->urls()[1600] ?? null)->filter()->take(5)->values()->all() ?: null,
            'brand' => $v->make ? ['@type' => 'Brand', 'name' => $v->make->name] : null,
            'model' => $v->model?->name,
            'vehicleModelDate' => $v->year ? (string) $v->year : null,
            'mileageFromOdometer' => $v->mileage_km !== null ? ['@type' => 'QuantitativeValue', 'value' => $v->mileage_km, 'unitCode' => 'KMT'] : null,
            'vehicleTransmission' => $v->transmission?->label(),
            'fuelType' => $v->fuel?->label(),
            'bodyType' => $v->body_type?->label(),
            'color' => $v->colour,
            'itemCondition' => $v->condition === VehicleCondition::New ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition',
            'offers' => $v->price ? [
                '@type' => 'Offer',
                'price' => intdiv($v->price, 100),
                'priceCurrency' => $v->currency,
                'availability' => match ($v->status) {
                    VehicleStatus::Available => 'https://schema.org/InStock',
                    VehicleStatus::Reserved => 'https://schema.org/LimitedAvailability',
                    default => 'https://schema.org/SoldOut',
                },
                'url' => url($v->publicPath()),
                'seller' => ['@type' => 'AutoDealer', 'name' => $v->lot->name, 'url' => route('lots.show', $v->lot)],
            ] : null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    public static function lot(Lot $lot): array
    {
        $lot->loadMissing('hours');
        $rating = $lot->publicRating();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'AutoDealer',
            'name' => $lot->name,
            'url' => route('lots.show', $lot),
            'description' => $lot->tagline,
            'image' => $lot->cover_url ?? $lot->logo_url,
            'logo' => $lot->logo_url,
            'telephone' => $lot->phone,
            'address' => $lot->city ? array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $lot->address,
                'addressLocality' => $lot->city,
                'addressRegion' => $lot->state,
                'addressCountry' => $lot->country,
            ]) : null,
            'geo' => $lot->hasLocation() ? ['@type' => 'GeoCoordinates', 'latitude' => $lot->latitude, 'longitude' => $lot->longitude] : null,
            'openingHoursSpecification' => $lot->hours->reject(fn (LotHour $h) => $h->is_closed || ! $h->opens_at || ! $h->closes_at)->map(fn (LotHour $h) => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => LotHour::WEEKDAYS[$h->weekday],
                'opens' => substr((string) $h->opens_at, 0, 5),
                'closes' => substr((string) $h->closes_at, 0, 5),
            ])->values()->all() ?: null,
            'aggregateRating' => $rating !== null ? ['@type' => 'AggregateRating', 'ratingValue' => $rating, 'reviewCount' => $lot->reviews_count, 'bestRating' => 5] : null,
        ], fn ($value) => $value !== null);
    }

    /** Safe to print inside a <script> tag: <, > and & are escaped. @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
