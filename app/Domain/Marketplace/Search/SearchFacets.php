<?php

namespace App\Domain\Marketplace\Search;

use App\Domain\Finance\Models\Lender;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Regions;
use BackedEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The search filters' choices, from what is on sale right now: only makes, models, body types, places, colours,
 * features and extras that have cars, each with how many. One small cached set of grouped counts (fresh for
 * 5 minutes, then rebuilt after a response), so it costs nothing per search, also on shared hosting.
 */
final class SearchFacets
{
    public const CACHE_KEY = 'marketplace:facets';

    /** @return array<string, mixed> */
    public static function options(): array
    {
        return Cache::flexible(self::CACHE_KEY, [300, 1800], fn (): array => self::build());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        $stock = Vehicle::query()->marketplace()->toBase()->join('lots', 'lots.id', '=', 'vehicles.lot_id');
        $count = fn (string $column) => (clone $stock)->whereNotNull($column)->groupBy($column)
            ->select($column.' as value', DB::raw('count(*) as total'))->pluck('total', 'value')->map(fn ($n) => (int) $n);

        $makes = (clone $stock)->join('makes', 'makes.id', '=', 'vehicles.make_id')
            ->groupBy('makes.id', 'makes.name')->orderBy('makes.name')
            ->get(['makes.id', 'makes.name', DB::raw('count(*) as total')])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'count' => (int) $m->total]);

        $models = (clone $stock)->join('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
            ->groupBy('vehicle_models.id', 'vehicle_models.name', 'vehicle_models.make_id')->orderBy('vehicle_models.name')
            ->get(['vehicle_models.id', 'vehicle_models.name', 'vehicle_models.make_id', DB::raw('count(*) as total')])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'make_id' => (int) $m->make_id, 'count' => (int) $m->total]);

        $states = $count('lots.state');
        $regions = collect(Regions::options())->keyBy('value');

        $cities = (clone $stock)->whereNotNull('lots.city')->groupBy('lots.city', 'lots.state')->orderBy('lots.city')
            ->get(['lots.city', 'lots.state', DB::raw('count(*) as total')])
            ->map(fn ($c) => ['value' => $c->city, 'label' => $c->city, 'state' => $c->state, 'count' => (int) $c->total]);

        // Colours are typed by lots ("Silver", "silver "), so they're grouped without case or spaces.
        $colours = (clone $stock)->whereNotNull('vehicles.colour')->where('vehicles.colour', '!=', '')
            ->groupBy(DB::raw('LOWER(TRIM(vehicles.colour))'))
            ->select(DB::raw('LOWER(TRIM(vehicles.colour)) as value'), DB::raw('count(*) as total'))->get()
            ->map(fn ($c) => ['value' => $c->value, 'label' => Str::ucfirst($c->value), 'count' => (int) $c->total])
            ->sortByDesc('count')->values();

        $features = (clone $stock)->join('vehicle_features', 'vehicle_features.vehicle_id', '=', 'vehicles.id')
            ->join('features', 'features.id', '=', 'vehicle_features.feature_id')
            ->groupBy('features.id', 'features.name', 'features.group')->orderBy('features.name')
            ->get(['features.id', 'features.name', 'features.group', DB::raw('count(*) as total')])
            ->map(fn ($f) => ['id' => (int) $f->id, 'name' => $f->name, 'group' => $f->group, 'count' => (int) $f->total]);

        $ranges = (clone $stock)->first([
            DB::raw('MIN(vehicles.year) as year_min'), DB::raw('MAX(vehicles.year) as year_max'),
            DB::raw('MIN(vehicles.price) as price_min'), DB::raw('MAX(vehicles.price) as price_max'),
        ]);

        return [
            'makes' => $makes->values()->all(),
            'models' => $models->values()->all(),
            'body_types' => self::enum(BodyType::cases(), $count('vehicles.body_type')),
            'conditions' => self::enum(VehicleCondition::cases(), $count('vehicles.condition')),
            'transmissions' => self::enum(Transmission::cases(), $count('vehicles.transmission')),
            'fuels' => self::enum(FuelType::cases(), $count('vehicles.fuel')),
            'drivetrains' => self::enum(Drivetrain::cases(), $count('vehicles.drivetrain')),
            'colours' => $colours->all(),
            'states' => $states->map(fn (int $n, string $state) => ['value' => $state, 'label' => $regions[$state]['label'] ?? $state, 'count' => $n])
                ->sortBy('label')->values()->all(),
            'cities' => $cities->values()->all(),
            'features' => $features->values()->all(),
            'extras' => self::extras(clone $stock),
            'years' => ['min' => $ranges?->year_min !== null ? (int) $ranges->year_min : null, 'max' => $ranges?->year_max !== null ? (int) $ranges->year_max : null],
            'prices' => [
                'min' => $ranges?->price_min !== null ? intdiv((int) $ranges->price_min, 100) : null,
                'max' => $ranges?->price_max !== null ? intdiv((int) $ranges->price_max, 100) : null,
            ],
            'radii' => SearchCriteria::RADII_KM,
        ];
    }

    /**
     * Enum choices that have cars, in the enum's order.
     *
     * @param  list<BackedEnum>  $cases
     * @param  Collection<array-key, int>  $counts
     * @return list<array{value: string, label: string, count: int}>
     */
    private static function enum(array $cases, $counts): array
    {
        $out = [];
        foreach ($cases as $case) {
            if (($counts[$case->value] ?? 0) > 0) {
                $out[] = ['value' => (string) $case->value, 'label' => method_exists($case, 'label') ? $case->label() : (string) $case->value, 'count' => $counts[$case->value]];
            }
        }

        return $out;
    }

    /**
     * The yes/no filters with cars behind them. Car loans only show while an approved lender is taking applications.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private static function extras(Builder $stock): array
    {
        $offerPlans = Plan::query()->get()->filter(fn (Plan $p) => $p->allows('offers'));
        $planIds = $offerPlans->pluck('id')->map(fn ($id) => (int) $id)->all();
        $defaultAllows = $offerPlans->contains('code', config('lotlink.default_plan'));
        $offers = sprintf('lots.accepts_offers = 1 AND (lots.plan_id IN (%s)%s)', $planIds === [] ? 'NULL' : implode(',', $planIds), $defaultAllows ? ' OR lots.plan_id IS NULL' : '');

        $sum = fn (string $condition, string $as) => DB::raw("SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) as {$as}");
        $row = $stock->first([
            $sum('lots.accepts_finance = 1', 'loans'),
            $sum('lots.accepts_trade_ins = 1', 'trade_ins'),
            $sum($offers, 'offers'),
            $sum('vehicles.negotiable = 1', 'negotiable'),
            $sum('vehicles.inspection_id IS NOT NULL', 'inspected'),
            $sum('lots.verified_at IS NOT NULL', 'verified_lot'),
            $sum("vehicles.duty_status = 'paid'", 'duty_paid'),
            $sum('vehicles.registered = 1', 'registered'),
        ]);
        $lenders = Lender::query()->where('status', 'active')->exists();

        $out = [];
        foreach (SearchCriteria::EXTRAS as $value => $label) {
            $n = (int) ($row->{$value} ?? 0);
            if ($n > 0 && ($value !== 'loans' || $lenders)) {
                $out[] = ['value' => $value, 'label' => $label, 'count' => $n];
            }
        }

        return $out;
    }
}
