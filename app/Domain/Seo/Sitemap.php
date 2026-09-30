<?php

namespace App\Domain\Seo;

use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Storage;

/**
 * sitemap.xml (TDD M18): the home and search pages, landing pages that have stock, live lots and
 * marketplace cars. Rebuilt nightly by `sitemap:generate` and served from the private disk.
 */
final class Sitemap
{
    public const PATH = 'sitemap.xml';

    /** A single sitemap file holds at most 50,000 URLs. */
    public const LIMIT = 50_000;

    public static function generate(): int
    {
        $urls = self::urls();
        Storage::disk('local')->put(self::PATH, self::xml($urls));

        return count($urls);
    }

    public static function contents(): string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::PATH)) {
            self::generate();
        }

        return (string) $disk->get(self::PATH);
    }

    /** @return list<array{loc: string, lastmod?: string|null, priority: string}> */
    public static function urls(): array
    {
        $urls = [
            ['loc' => url('/'), 'priority' => '1.0'],
            ['loc' => route('cars.index'), 'priority' => '0.9'],
            ['loc' => route('lenders.join'), 'priority' => '0.4'],
        ];
        foreach (array_keys(LegalDocuments::TITLES) as $doc) {
            $urls[] = ['loc' => route('legal.show', $doc), 'lastmod' => LegalDocuments::version($doc), 'priority' => '0.3'];
        }

        // Landing pages with stock: makes, make + model, cities, make + city, make + model + city.
        $stock = Vehicle::query()->marketplace()->join('lots as l', 'l.id', '=', 'vehicles.lot_id')->toBase()
            ->selectRaw('vehicles.make_id, vehicles.vehicle_model_id, l.city')->whereNotNull('vehicles.make_id')->distinct()->get();
        $makes = Make::whereIn('id', $stock->pluck('make_id')->unique())->get()->keyBy('id');
        $models = VehicleModel::whereIn('id', $stock->pluck('vehicle_model_id')->filter()->unique())->get()->keyBy('id');
        $pages = [];

        foreach ($stock as $row) {
            $make = $makes[$row->make_id] ?? null;
            $model = $row->vehicle_model_id ? ($models[$row->vehicle_model_id] ?? null) : null;
            $city = $row->city ?: null;
            foreach ([[$make, null, null], [$make, $model, null], [null, null, $city], [$make, null, $city], [$make, $model, $city]] as [$ma, $mo, $ci]) {
                if ($ma === null && $ci === null) {
                    continue;
                }
                $pages[Landing::url($ma, $mo, $ci)] = true;
            }
        }
        foreach (array_keys($pages) as $loc) {
            $urls[] = ['loc' => $loc, 'priority' => '0.8'];
        }

        foreach (Lot::where('status', LotStatus::Active)->get(['id', 'slug', 'updated_at']) as $lot) {
            $urls[] = ['loc' => route('lots.show', $lot), 'lastmod' => $lot->updated_at?->toAtomString(), 'priority' => '0.7'];
        }

        Vehicle::query()->marketplace()->select(['vehicles.id', 'vehicles.ulid', 'vehicles.slug', 'vehicles.updated_at'])
            ->orderByDesc('vehicles.listed_at')->limit(self::LIMIT)->get()
            ->each(function (Vehicle $v) use (&$urls): void {
                $urls[] = ['loc' => url($v->publicPath()), 'lastmod' => $v->updated_at?->toAtomString(), 'priority' => '0.6'];
            });

        return array_slice($urls, 0, self::LIMIT);
    }

    /** @param list<array{loc: string, lastmod?: string|null, priority: string}> $urls */
    public static function xml(array $urls): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $u) {
            $out .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>'
                .(! empty($u['lastmod']) ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '')
                .'<priority>'.$u['priority'].'</priority></url>'."\n";
        }

        return $out.'</urlset>'."\n";
    }
}
