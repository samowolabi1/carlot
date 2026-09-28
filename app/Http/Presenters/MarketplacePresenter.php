<?php

namespace App\Http\Presenters;

use App\Domain\Inventory\Enums\FeatureGroup;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Feature;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Support\Geo;
use App\Domain\Marketplace\Support\OpeningHours;
use App\Domain\Support\PhoneNumber;

/**
 * Shapes cars and lots for public pages. Only fields meant for buyers leave here:
 * no VIN beyond the last four characters, no internal ids, no dealer-only data.
 */
class MarketplacePresenter
{
    /** @param array{lat: float, lng: float}|null $from */
    public static function card(Vehicle $v, ?array $from = null, array $savedIds = []): array
    {
        $lot = $v->lot;

        return [
            'ulid' => $v->ulid,
            'url' => $v->publicPath(),
            'title' => $v->title(),
            'price' => $v->formattedPrice(),
            'price_value' => $v->price !== null ? intdiv($v->price, 100) : null,
            'specs' => implode(' · ', array_filter([
                $v->mileage_km !== null ? number_format($v->mileage_km).' km' : null,
                $v->transmission?->label(),
                $v->fuel?->label(),
            ])),
            'image' => self::image($v->cover),
            'lot' => ['name' => $lot->name, 'slug' => $lot->slug, 'city' => $lot->city],
            'distance' => self::distance($lot, $from),
            'new_arrival' => $v->isNewArrival(),
            'reserved' => $v->status === VehicleStatus::Reserved,
            'saved' => in_array($v->id, $savedIds, true),
        ];
    }

    /** @return array{src: string, srcset: string}|null */
    public static function image(?VehicleMedia $media): ?array
    {
        $urls = $media?->urls() ?? [];

        if ($urls === []) {
            return null;
        }

        return [
            'src' => $urls[800],
            'srcset' => implode(', ', array_map(fn ($w, $url) => "{$url} {$w}w", array_keys($urls), $urls)),
        ];
    }

    public static function vehicle(Vehicle $v): array
    {
        $specs = array_values(array_filter([
            ['label' => 'Mileage', 'value' => $v->mileage_km !== null ? number_format($v->mileage_km).' km' : null],
            ['label' => 'Gearbox', 'value' => $v->transmission?->label()],
            ['label' => 'Engine', 'value' => trim(($v->engine_cc ? number_format($v->engine_cc / 1000, 1).'L ' : '').strtolower((string) $v->fuel?->label())) ?: null],
            ['label' => 'Condition', 'value' => $v->condition?->label()],
            ['label' => 'Body', 'value' => $v->body_type?->label()],
            ['label' => 'Year', 'value' => $v->year ? (string) $v->year : null],
            ['label' => 'Duty', 'value' => match ($v->duty_status?->value) {
                'paid' => 'Paid', 'unpaid' => 'Not paid', default => null,
            }],
            ['label' => 'Colour', 'value' => $v->colour],
            ['label' => 'Interior', 'value' => $v->interior_colour],
            ['label' => 'Drive', 'value' => $v->drivetrain?->label()],
            ['label' => 'Registered', 'value' => $v->registered ? 'Yes, has plates' : null],
            ['label' => 'VIN', 'value' => $v->vinTail() ? '···'.$v->vinTail() : null],
        ], fn (array $s) => $s['value'] !== null));

        return [
            'ulid' => $v->ulid,
            'url' => url($v->publicPath()),
            'title' => $v->title(),
            'make' => $v->make?->name,
            'model' => $v->model?->name,
            'make_id' => $v->make_id,
            'price' => $v->formattedPrice(),
            'negotiable' => $v->negotiable,
            'description' => $v->description,
            'specs' => $specs,
            'features' => collect(FeatureGroup::cases())
                ->map(fn (FeatureGroup $g) => [
                    'group' => $g->label(),
                    'items' => $v->features->filter(fn (Feature $f) => $f->group === $g)->pluck('name')->values(),
                ])
                ->filter(fn ($g) => $g['items']->isNotEmpty())
                ->values(),
            'photos' => $v->media->map(fn (VehicleMedia $m) => self::image($m))->filter()->values(),
            'new_arrival' => $v->isNewArrival(),
            'reserved' => $v->status === VehicleStatus::Reserved,
            'listed_days' => $v->daysListed(),
        ];
    }

    public static function lot(Lot $lot): array
    {
        $hours = OpeningHours::for($lot);

        return [
            'slug' => $lot->slug,
            'url' => route('lots.show', $lot),
            'name' => $lot->name,
            'initials' => $lot->initials(),
            'tagline' => $lot->tagline,
            'about' => $lot->about,
            'logo_url' => $lot->logo_url,
            'cover_url' => $lot->cover_url,
            'brand_color' => $lot->brand_color,
            'verified' => $lot->verified_at !== null,
            'address' => $lot->address,
            'landmark' => $lot->landmark,
            'city' => $lot->city,
            'state' => $lot->state,
            'location' => $lot->hasLocation() ? ['lat' => $lot->latitude, 'lng' => $lot->longitude] : null,
            'directions_url' => $lot->directionsUrl(),
            'phone' => $lot->phone,
            'phone_display' => $lot->phone ? PhoneNumber::display($lot->phone) : null,
            'whatsapp' => $lot->whatsapp ? ltrim($lot->whatsapp, '+') : null,
            'open' => $hours->status(),
            'hours' => $hours->table(),
        ];
    }

    /** @param array{lat: float, lng: float}|null $from */
    private static function distance(Lot $lot, ?array $from): ?string
    {
        if ($from === null || ! $lot->hasLocation()) {
            return null;
        }

        return Geo::format(Geo::distanceKm($from['lat'], $from['lng'], (float) $lot->latitude, (float) $lot->longitude));
    }
}
