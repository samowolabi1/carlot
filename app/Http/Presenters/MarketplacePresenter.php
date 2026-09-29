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
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Models\Review;

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
            'sponsored' => $v->spotlight_until?->isFuture() ?? false,
            'inspected' => $v->inspection_id !== null,
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
            'inspection' => $v->inspection ? self::inspection($v->inspection, $v->lot->timezone) : null,
        ];
    }

    /** The car's current inspection report (TDD M14), as the car page and compare show it. */
    public static function inspection(Inspection $i, string $timezone = 'Africa/Lagos'): array
    {
        return [
            'ulid' => $i->ulid,
            'score' => $i->score,
            'independent' => $i->isIndependent(),
            'label' => $i->isIndependent() ? 'Independently inspected' : 'Inspected by the lot',
            'inspector' => $i->inspector_name,
            'date' => $i->created_at?->timezone($timezone)->format('j M Y'),
            'summary' => $i->summary,
            'groups' => $i->summaryRows(),
            'photos' => $i->photoUrls(),
            'pdf_url' => route('inspections.pdf', $i),
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
            'rating' => $lot->publicRating(),
            'reviews_count' => $lot->reviews_count,
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

    /** A visible review on the lot's pages: first name and initial only (design 17). */
    public static function review(Review $r, string $timezone, ?int $viewerId = null): array
    {
        return [
            'mine' => $viewerId !== null && $r->user_id === $viewerId,
            'ulid' => $r->ulid,
            'author' => $r->authorName(),
            'rating' => $r->rating,
            'body' => $r->body,
            'tags' => $r->tagLabels(),
            'visit' => $r->appointment?->type->label(),
            'date' => $r->created_at->copy()->setTimezone($timezone)->format('M Y'),
            'reply' => $r->reply,
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
