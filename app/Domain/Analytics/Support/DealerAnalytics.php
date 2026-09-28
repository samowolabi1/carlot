<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Analytics\Models\DailyVehicleStat;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Sharing\Models\ShareLink;
use App\Domain\Support\Name;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The dealer analytics page (TDD M15, design D8): totals with the change on the previous
 * period, the funnel, daily trend, lead sources, shares by platform, cars with ageing flags,
 * and staff performance. Views, saves, shares, leads and bookings come from the rollups.
 */
final class DealerAnalytics
{
    public const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];

    public const AGEING_DAYS = 45;

    public const STALE_DAYS = 90;

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last local day */
    public static function range(Lot $lot, string $period): array
    {
        $days = self::PERIODS[$period] ?? 30;
        $today = CarbonImmutable::now($lot->timezone)->startOfDay();

        return [$today->subDays($days - 1), $today];
    }

    /** @return array<string, int> */
    public static function totals(Lot $lot, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stats = DailyVehicleStat::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('coalesce(sum(views),0) as views, coalesce(sum(saves),0) as saves, coalesce(sum(shares),0) as shares')->first();
        [$utcFrom, $utcTo] = [$from->startOfDay()->utc(), $to->endOfDay()->utc()];

        return [
            'views' => (int) $stats?->getAttribute('views'),
            'saves' => (int) $stats?->getAttribute('saves'),
            'shares' => (int) $stats?->getAttribute('shares'),
            // Leads and bookings are counted from their own tables, so lot-level ones count too.
            'leads' => Lead::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('created_at', [$utcFrom, $utcTo])->count(),
            'bookings' => Appointment::withoutGlobalScopes()->where('lot_id', $lot->id)->whereNotIn('status', ['awaiting_deposit'])->whereBetween('created_at', [$utcFrom, $utcTo])->count(),
            'sold' => SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$utcFrom, $utcTo])->count(),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(Lot $lot, string $period, bool $full): array
    {
        [$from, $to] = self::range($lot, $period);
        $days = (int) $from->diffInDays($to) + 1;
        $now = self::totals($lot, $from, $to);
        $before = self::totals($lot, $from->subDays($days), $from->subDay());
        $change = fn (string $k) => $before[$k] > 0 ? (int) round(($now[$k] - $before[$k]) / $before[$k] * 100) : null;

        $stock = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->whereIn('status', [VehicleStatus::Available, VehicleStatus::Reserved])
            ->with(['make', 'model'])->get();
        $daysInStock = $stock->map(fn (Vehicle $v) => $v->daysListed())->filter(fn ($d) => $d !== null);

        return [
            'range' => $from->format('j M').' – '.$to->format('j M Y'),
            'kpis' => [
                ['key' => 'views', 'label' => 'Listing views', 'value' => $now['views'], 'change' => $change('views')],
                ['key' => 'leads', 'label' => 'Leads', 'value' => $now['leads'], 'change' => $change('leads')],
                ['key' => 'bookings', 'label' => 'Visits booked', 'value' => $now['bookings'], 'change' => $change('bookings')],
                ['key' => 'sold', 'label' => 'Cars sold', 'value' => $now['sold'], 'change' => $change('sold')],
                ['key' => 'days', 'label' => 'Avg days in stock', 'value' => $daysInStock->isEmpty() ? 0 : (int) round($daysInStock->avg()), 'change' => null],
            ],
            'funnel' => self::funnel($now),
            'trend' => self::trend($lot, $from, $to),
            'cars' => self::cars($lot, $stock, $from, $to),
            'sources' => $full ? self::sources($lot, $from, $to) : null,
            'shares' => $full ? self::shares($lot, $from, $to) : null,
            'staff' => $full ? self::staff($lot, $from, $to) : null,
        ];
    }

    /** @param array<string, int> $t @return list<array{label: string, value: int, note: ?string}> */
    private static function funnel(array $t): array
    {
        $pct = fn (int $a, int $b) => $b > 0 ? rtrim(rtrim(number_format($a / $b * 100, $a / $b < 0.1 ? 2 : 0), '0'), '.').'%' : null;

        return [
            ['label' => 'views', 'value' => $t['views'], 'note' => null],
            ['label' => 'leads', 'value' => $t['leads'], 'note' => ($p = $pct($t['leads'], $t['views'])) ? "{$p} of views" : null],
            ['label' => 'visits', 'value' => $t['bookings'], 'note' => ($p = $pct($t['bookings'], $t['leads'])) ? "{$p} of leads" : null],
            ['label' => 'sales', 'value' => $t['sold'], 'note' => ($p = $pct($t['sold'], $t['bookings'])) ? "{$p} of visits" : null],
        ];
    }

    /** Views and leads per day. @return list<array{date: string, label: string, views: int, leads: int}> */
    private static function trend(Lot $lot, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DailyVehicleStat::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date, sum(views) as views, sum(leads) as leads')->groupBy('date')->get()
            ->keyBy(fn ($r) => substr((string) $r->getRawOriginal('date'), 0, 10));

        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $r = $rows->get($d->toDateString());
            $out[] = ['date' => $d->toDateString(), 'label' => $d->format('j M'), 'views' => (int) ($r?->getAttribute('views') ?? 0), 'leads' => (int) ($r?->getAttribute('leads') ?? 0)];
        }

        return $out;
    }

    /**
     * Cars in stock with their numbers and a flag: ageing at 45 days, stale at 90 (TDD M15).
     *
     * @param  Collection<int, Vehicle>  $stock
     * @return list<array<string, mixed>>
     */
    private static function cars(Lot $lot, $stock, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stats = DailyVehicleStat::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('vehicle_id, sum(views) as views, sum(saves) as saves, sum(leads) as leads')->groupBy('vehicle_id')->get()->keyBy('vehicle_id');

        return $stock->map(function (Vehicle $v) use ($stats, $lot) {
            $s = $stats->get($v->id);
            $days = $v->daysListed();
            $views = (int) ($s?->getAttribute('views') ?? 0);
            $leads = (int) ($s?->getAttribute('leads') ?? 0);
            $guide = $v->price ? PricingGuide::for($v) : null;

            return [
                'ulid' => $v->ulid,
                'title' => $v->title(),
                'views' => $views,
                'saves' => (int) ($s?->getAttribute('saves') ?? 0),
                'leads' => $leads,
                'days' => $days,
                'flag' => match (true) {
                    $v->status === VehicleStatus::Reserved => 'reserved',
                    ($days ?? 0) >= self::STALE_DAYS => 'stale',
                    ($days ?? 0) >= self::AGEING_DAYS => 'ageing',
                    $leads > 0 => 'good',
                    default => 'quiet',
                },
                'guide' => $guide ? PricingGuide::position((int) $v->price, $guide) : null,
                'price_url' => route('dealer.vehicles.edit', [$lot, $v, 'price']),
                'spotlight' => $v->status === VehicleStatus::Available,
            ];
        })->sortByDesc('views')->values()->all();
    }

    /** @return list<array{label: string, value: int}> */
    private static function sources(Lot $lot, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Lead::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('created_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->selectRaw('source, count(*) as n')->groupBy('source')->get()
            ->map(fn ($r) => ['label' => $r->source->label(), 'value' => (int) $r->getAttribute('n')])
            ->sortByDesc('value')->values()->all();
    }

    /** Share links made in the period, and the visits they brought. @return list<array{label: string, value: int, clicks: int}> */
    private static function shares(Lot $lot, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ShareLink::query()->where('lot_id', $lot->id)->whereBetween('created_at', [$from->startOfDay()->utc(), $to->endOfDay()->utc()])
            ->get(['platform', 'clicks'])->groupBy(fn (ShareLink $l) => $l->platform->label())
            ->map(fn ($links, $label) => ['label' => (string) $label, 'value' => $links->count(), 'clicks' => (int) $links->sum('clicks')])
            ->sortByDesc('value')->values()->all();
    }

    /** Leads handled, average first reply, bookings looked after and cars sold, per person (TDD M15). @return list<array<string, mixed>> */
    private static function staff(Lot $lot, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$utcFrom, $utcTo] = [$from->startOfDay()->utc(), $to->endOfDay()->utc()];
        $leads = Lead::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('created_at', [$utcFrom, $utcTo])->get(['assigned_to', 'created_at', 'first_response_at']);
        $bookings = Appointment::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('starts_at', [$utcFrom, $utcTo])->whereNotNull('staff_id')
            ->selectRaw('staff_id, count(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');
        $sold = SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$utcFrom, $utcTo])
            ->selectRaw('staff_id, count(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');

        $reply = function ($group): ?string {
            $minutes = $group->filter(fn ($l) => $l->first_response_at !== null)->map(fn ($l) => $l->created_at->diffInMinutes($l->first_response_at));

            return $minutes->isEmpty() ? null : self::duration((int) round($minutes->avg()));
        };

        $rows = LotMember::query()->where('lot_id', $lot->id)->with('user')->get()->map(fn (LotMember $m) => [
            'name' => Name::short($m->user->name ?? $m->user->phone),
            'leads' => $leads->where('assigned_to', $m->user_id)->count(),
            'reply' => $reply($leads->where('assigned_to', $m->user_id)),
            'bookings' => (int) ($bookings[$m->user_id] ?? 0),
            'sold' => (int) ($sold[$m->user_id] ?? 0),
        ])->sortByDesc('leads')->values()->all();

        $unassigned = $leads->whereNull('assigned_to')->count();
        if ($unassigned > 0) {
            $rows[] = ['name' => 'Unassigned', 'leads' => $unassigned, 'reply' => null, 'bookings' => null, 'sold' => null];
        }

        return $rows;
    }

    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => round($minutes / 60, 1).' h',
            default => round($minutes / 1440, 1).' days',
        };
    }
}
