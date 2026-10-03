<?php

namespace App\Domain\Seo;

use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SEO landing pages built from stock (TDD M18): /cars/{city}, /cars/{make}, /cars/{make}/{model},
 * /cars/{make}/{city}, /cars/{make}/{model}/{city}. A first segment is a make when one has that
 * slug, otherwise a city with a live lot.
 */
final class Landing
{
    private function __construct(
        public readonly ?Make $make,
        public readonly ?VehicleModel $model,
        public readonly ?string $city,
    ) {}

    /** @param list<string> $segments */
    public static function resolve(array $segments): ?self
    {
        $segments = array_values(array_filter(array_map(fn ($s) => Str::lower((string) $s), $segments)));
        $cities = self::cities();

        [$first, $second, $third] = [$segments[0] ?? null, $segments[1] ?? null, $segments[2] ?? null];
        $make = $first ? Make::where('slug', $first)->first() : null;

        if ($make === null) {
            // /cars/{city}
            return $first !== null && $second === null && isset($cities[$first]) ? new self(null, null, $cities[$first]) : null;
        }

        if ($second === null) {
            return new self($make, null, null);
        }

        $model = VehicleModel::where('make_id', $make->id)->where('slug', $second)->first();

        if ($model === null) {
            // /cars/{make}/{city}
            return $third === null && isset($cities[$second]) ? new self($make, null, $cities[$second]) : null;
        }

        if ($third === null) {
            return new self($make, $model, null);
        }

        return isset($cities[$third]) ? new self($make, $model, $cities[$third]) : null;
    }

    /** Cities with a live lot, slug => name as the sellers write it ("Ikeja"). @return array<string, string> */
    public static function cities(): array
    {
        return Cache::remember('seo:cities', now()->addMinutes(30), fn () => Lot::where('status', LotStatus::Active)
            ->whereNotNull('city')->distinct()->orderBy('city')->pluck('city')
            ->mapWithKeys(fn (string $city) => [Str::slug($city) => $city])
            ->filter(fn ($city, $slug) => $slug !== '')->all());
    }

    public static function url(?Make $make = null, ?VehicleModel $model = null, ?string $city = null): string
    {
        return url('/cars/'.implode('/', array_filter([$make?->slug, $make ? $model?->slug : null, $city ? Str::slug($city) : null])));
    }

    public function canonical(): string
    {
        return self::url($this->make, $this->model, $this->city);
    }

    /** "Toyota Camry", "Toyota" or "" */
    public function what(): string
    {
        return trim((string) $this->make?->name.' '.(string) $this->model?->name);
    }

    /** "Used Toyota Camry for sale in Ikeja" */
    public function heading(): string
    {
        return trim('Used '.($this->what() ?: 'cars').($this->what() ? ' cars' : '').' for sale'.($this->city ? " in {$this->city}" : ''));
    }

    public function title(): string
    {
        return $this->heading().' | CarYard';
    }

    /**
     * Intro text from the live stock, so each page reads differently.
     *
     * @param  array{count: int, lots: int, min: int|null, max: int|null}  $stats  prices in kobo
     */
    public function intro(array $stats): string
    {
        if ($stats['count'] === 0) {
            return 'No '.($this->what() ?: 'cars').' are listed'.($this->city ? " in {$this->city}" : '').' right now. New stock arrives every day: save this search to hear when one is listed.';
        }

        $range = $stats['min'] !== null && $stats['max'] !== null && $stats['min'] !== $stats['max']
            ? ' from ₦'.number_format(intdiv($stats['min'], 100)).' to ₦'.number_format(intdiv($stats['max'], 100))
            : ($stats['min'] !== null ? ' at ₦'.number_format(intdiv($stats['min'], 100)) : '');

        return sprintf(
            '%s %s %s for sale%s at %s %s on CarYard%s. See real photos and prices, check what you can afford, and book a viewing or test drive in two taps.',
            number_format($stats['count']),
            $this->what() ?: 'used',
            $stats['count'] === 1 ? 'car' : 'cars',
            $this->city ? " in {$this->city}" : '',
            $stats['lots'],
            $stats['lots'] === 1 ? 'seller' : 'sellers',
            $range,
        );
    }

    /** @return array{count: int, lots: int, min: int|null, max: int|null} */
    public function stats(): array
    {
        $row = $this->stock()->toBase()
            ->selectRaw('count(*) as total, count(distinct vehicles.lot_id) as lots, min(vehicles.price) as low, max(vehicles.price) as high')->first();

        return [
            'count' => (int) ($row->total ?? 0),
            'lots' => (int) ($row->lots ?? 0),
            'min' => $row?->low !== null ? (int) $row->low : null,
            'max' => $row?->high !== null ? (int) $row->high : null,
        ];
    }

    /**
     * Links to narrower pages with stock, for people and for crawlers.
     *
     * @return list<array{label: string, url: string, count: int}>
     */
    public function related(): array
    {
        $links = [];

        if ($this->make === null) {
            // A city page: makes in that city.
            $rows = $this->stock()->toBase()->join('makes', 'makes.id', '=', 'vehicles.make_id')
                ->selectRaw('makes.id, count(*) as total')->groupBy('makes.id')->orderByDesc('total')->limit(12)->pluck('total', 'makes.id');
            foreach (Make::whereIn('id', $rows->keys())->get() as $make) {
                $links[] = ['label' => $make->name.' in '.$this->city, 'url' => self::url($make, null, $this->city), 'count' => (int) $rows[$make->id]];
            }
        } elseif ($this->model === null && $this->city === null) {
            $rows = $this->stock()->toBase()->selectRaw('vehicles.vehicle_model_id as id, count(*) as total')->whereNotNull('vehicles.vehicle_model_id')
                ->groupBy('vehicles.vehicle_model_id')->orderByDesc('total')->limit(12)->pluck('total', 'id');
            foreach (VehicleModel::whereIn('id', $rows->keys())->get() as $model) {
                $links[] = ['label' => $this->make->name.' '.$model->name, 'url' => self::url($this->make, $model), 'count' => (int) $rows[$model->id]];
            }
        }

        if ($this->make !== null && $this->city === null) {
            $rows = $this->stock()->toBase()->join('lots as l', 'l.id', '=', 'vehicles.lot_id')->whereNotNull('l.city')
                ->selectRaw('l.city as city, count(*) as total')->groupBy('l.city')->orderByDesc('total')->limit(12)->pluck('total', 'city');
            foreach ($rows as $city => $total) {
                $links[] = ['label' => $this->what().' in '.$city, 'url' => self::url($this->make, $this->model, (string) $city), 'count' => (int) $total];
            }
        }

        usort($links, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_values(array_filter($links, fn ($l) => $l['count'] > 0));
    }

    /** @return array<string, mixed> the filters these segments stand for, as /cars reads them */
    public function filters(): array
    {
        return array_filter([
            'make' => $this->make ? [$this->make->id] : null,
            'model' => $this->model?->id,
            'city' => $this->city,
        ]);
    }

    /** @return Builder<Vehicle> */
    private function stock()
    {
        return Vehicle::query()->marketplace()
            ->when($this->make, fn ($q) => $q->where('vehicles.make_id', $this->make->id))
            ->when($this->model, fn ($q) => $q->where('vehicles.vehicle_model_id', $this->model->id))
            ->when($this->city, fn ($q) => $q->whereHas('lot', fn ($l) => $l->where('city', $this->city)));
    }
}
