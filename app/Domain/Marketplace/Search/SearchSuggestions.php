<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Seo\Landing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What the search box suggests as someone types (makes, models, places, lots and a few cars).
 *
 * Built to answer in a few milliseconds: makes, models, cities and lots come from small lists of what is
 * actually on the marketplace, cached for a few minutes and matched in PHP; only the cars go to the search
 * engine (Meilisearch, or MySQL when it's off or down). Each answer is cached for a minute per query.
 */
final class SearchSuggestions
{
    public const MIN_LENGTH = 2;

    public function __construct(private readonly VehicleSearch $search) {}

    /** @return array{query: string, makes: list<array<string, mixed>>, models: list<array<string, mixed>>, places: list<array<string, mixed>>, lots: list<array<string, mixed>>, cars: list<array<string, mixed>>} */
    public function for(string $query, bool $withCars = true): array
    {
        $q = Str::of($query)->squish()->lower()->limit(80, '')->value();
        $empty = ['query' => $q, 'makes' => [], 'models' => [], 'places' => [], 'lots' => [], 'cars' => []];

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return $empty;
        }

        return Cache::remember('suggest:'.($withCars ? 'c:' : '').md5($q), now()->addMinute(), function () use ($q, $withCars, $empty): array {
            $catalogue = self::catalogue();

            return [
                ...$empty,
                'makes' => self::top($catalogue['makes'], $q, 3),
                'models' => self::top($catalogue['models'], $q, 4),
                'places' => self::top($catalogue['places'], $q, 3),
                'lots' => self::top($catalogue['lots'], $q, 3),
                'cars' => $withCars ? $this->cars($q) : [],
            ];
        });
    }

    /**
     * Makes, models, places and lots with cars on sale right now, each with a label to match and a link.
     *
     * @return array<string, list<array{label: string, match: string, url: string, count?: int, detail?: string}>>
     */
    public static function catalogue(): array
    {
        // Fresh for 5 minutes; for the next 25 the old lists are served while new ones are built after the response,
        // so nobody waits for the rebuild.
        return Cache::flexible('suggest:catalogue', [300, 1800], function (): array {
            $stock = Vehicle::query()->marketplace()->toBase();

            $makes = (clone $stock)->join('makes', 'makes.id', '=', 'vehicles.make_id')
                ->groupBy('makes.id', 'makes.name', 'makes.slug')
                ->select('makes.name', 'makes.slug', DB::raw('count(*) as total'))->get()
                ->map(fn ($m) => ['label' => $m->name, 'match' => Str::lower($m->name), 'url' => url('/cars/'.$m->slug), 'count' => (int) $m->total]);

            $models = (clone $stock)->join('makes', 'makes.id', '=', 'vehicles.make_id')
                ->join('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
                ->groupBy('vehicle_models.id', 'vehicle_models.name', 'vehicle_models.slug', 'makes.name', 'makes.slug')
                ->select('vehicle_models.name', 'vehicle_models.slug', 'makes.name as make', 'makes.slug as make_slug', DB::raw('count(*) as total'))->get()
                ->map(fn ($m) => [
                    'label' => "{$m->make} {$m->name}",
                    'match' => Str::lower("{$m->make} {$m->name} {$m->name}"),
                    'url' => url("/cars/{$m->make_slug}/{$m->slug}"),
                    'count' => (int) $m->total,
                ]);

            $places = collect(Landing::cities())->map(fn (string $city, string $slug) => [
                'label' => "Cars in {$city}", 'match' => Str::lower($city), 'url' => url("/cars/{$slug}"),
            ])->values();

            $lots = Lot::query()->where('status', LotStatus::Active)->get(['name', 'slug', 'city', 'verified_at'])
                ->map(fn (Lot $lot) => [
                    'label' => $lot->name, 'match' => Str::lower($lot->name), 'url' => route('lots.show', $lot),
                    'detail' => collect([$lot->city, $lot->verified_at ? 'Verified' : null])->filter()->implode(' · '),
                ]);

            return ['makes' => $makes->values()->all(), 'models' => $models->values()->all(), 'places' => $places->all(), 'lots' => $lots->values()->all()];
        });
    }

    /**
     * Best matches first: the whole thing starts with the query, then a word does, then it appears anywhere.
     *
     * @param  list<array{label: string, match: string, url: string, count?: int, detail?: string}>  $items
     * @return list<array<string, mixed>>
     */
    private static function top(array $items, string $q, int $limit): array
    {
        $scored = [];
        foreach ($items as $item) {
            $score = match (true) {
                str_starts_with($item['match'], $q) => 3,
                str_contains(' '.$item['match'], ' '.$q) => 2,
                // Inside a word only from 3 letters, so "to" doesn't find "Prime Motors".
                mb_strlen($q) >= 3 && str_contains($item['match'], $q) => 1,
                default => 0,
            };
            if ($score > 0) {
                $scored[] = [$score, $item['count'] ?? 0, $item];
            }
        }
        usort($scored, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_map(function (array $row): array {
            unset($row[2]['match']);

            return $row[2];
        }, array_slice($scored, 0, $limit));
    }

    /** @return list<array<string, mixed>> */
    private function cars(string $q): array
    {
        return $this->search->search(new SearchCriteria(query: $q, perPage: 4))->getCollection()
            ->map(fn (Vehicle $v) => [
                'label' => $v->title(),
                'url' => url($v->publicPath()),
                'detail' => collect([$v->formattedPrice(), $v->lot->city])->filter()->implode(' · '),
                'image' => ($urls = $v->cover?->urls() ?? []) ? ($urls[400] ?? reset($urls)) : null,
            ])->values()->all();
    }
}
