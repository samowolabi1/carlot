<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\VehicleCost;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotMember;
use Carbon\CarbonImmutable;

/**
 * Sales Manager reports (TDD M19): sales and profit by month, outstanding balances, walk-ins by
 * source with conversion, and staff performance. Each returns the same shape so one page and
 * one Excel export serve them all. Money is whole naira here.
 *
 * @phpstan-type Column array{key: string, label: string, money?: bool, percent?: bool}
 * @phpstan-type Report array{title: string, columns: list<Column>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null}
 */
final class ManagerReports
{
    public const TYPES = ['sales' => 'Sales and profit', 'balances' => 'Balances', 'walk-ins' => 'Walk-ins', 'staff' => 'Staff'];

    public const PERIODS = ['30d' => 'Last 30 days', '90d' => 'Last 90 days', 'year' => 'This year', 'last-year' => 'Last year'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} local start and end of the period, as UTC */
    public static function range(Lot $lot, string $period): array
    {
        $now = CarbonImmutable::now($lot->timezone);

        [$from, $to] = match ($period) {
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            '90d' => [$now->subDays(89)->startOfDay(), $now->endOfDay()],
            'last-year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => [$now->startOfYear(), $now->endOfDay()],
        };

        return [$from->utc(), $to->utc()];
    }

    /** @return Report */
    public static function sales(Lot $lot, string $period, bool $withProfit): array
    {
        [$from, $to] = self::range($lot, $period);
        $tz = $lot->timezone;
        $month = fn ($date) => $date->copy()->setTimezone($tz)->format('Y-m');

        $delivered = SalesOrder::query()->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$from, $to])->get();
        $costs = $withProfit
            ? VehicleCost::query()->whereIn('vehicle_id', $delivered->pluck('vehicle_id'))->selectRaw('vehicle_id, sum(amount) as total')->groupBy('vehicle_id')->pluck('total', 'vehicle_id')
            : collect();
        $received = OrderPayment::query()->where('lot_id', $lot->id)->whereNull('voided_at')->whereBetween('paid_at', [$from, $to])->get();

        $months = [];
        for ($m = $from->setTimezone($tz)->startOfMonth(); $m->lte($to->setTimezone($tz)); $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('M Y'), 'cars' => 0, 'revenue' => 0, 'received' => 0, 'costs' => 0, 'profit' => 0];
        }

        foreach ($delivered as $o) {
            $key = $month($o->delivered_at);
            if (! isset($months[$key])) {
                continue;
            }
            $revenue = $o->agreed_price - $o->discount;
            $cost = (int) ($costs[$o->vehicle_id] ?? 0);
            $months[$key]['cars']++;
            $months[$key]['revenue'] += intdiv($revenue, 100);
            $months[$key]['costs'] += intdiv($cost, 100);
            $months[$key]['profit'] += intdiv($revenue - $cost, 100);
        }

        foreach ($received as $p) {
            $key = $month($p->paid_at);
            if (isset($months[$key])) {
                $months[$key]['received'] += intdiv($p->amount, 100);
            }
        }

        $columns = [
            ['key' => 'month', 'label' => 'Month'],
            ['key' => 'cars', 'label' => 'Cars sold'],
            ['key' => 'revenue', 'label' => 'Sales', 'money' => true],
            ['key' => 'received', 'label' => 'Money received', 'money' => true],
        ];
        if ($withProfit) {
            $columns[] = ['key' => 'costs', 'label' => 'Car costs', 'money' => true];
            $columns[] = ['key' => 'profit', 'label' => 'Profit', 'money' => true];
        }

        $rows = array_values(array_reverse($months));
        if (! $withProfit) {
            $rows = array_map(fn ($r) => array_diff_key($r, ['costs' => 1, 'profit' => 1]), $rows);
        }

        return ['title' => 'Sales and profit', 'columns' => $columns, 'rows' => $rows, 'totals' => self::totals($rows, $columns)];
    }

    /** @return Report */
    public static function balances(Lot $lot): array
    {
        $orders = SalesOrder::query()->whereIn('status', OrderStatus::open())->where('balance', '>', 0)
            ->with(['customer', 'vehicle.make', 'vehicle.model'])->orderByDesc('balance')->get();
        $instalments = Instalment::query()->whereIn('sales_order_id', $orders->pluck('id'))->where('status', '!=', InstalmentStatus::Paid)
            ->orderBy('sequence')->get()->groupBy('sales_order_id');

        $rows = $orders->map(function (SalesOrder $o) use ($instalments) {
            $plan = $instalments->get($o->id, collect());
            $next = $plan->first();

            return [
                'order' => $o->order_no,
                'customer' => $o->customer->name ?? '',
                'car' => $o->vehicle?->title() ?? '',
                'total' => intdiv($o->total(), 100),
                'paid' => intdiv($o->total_paid, 100),
                'balance' => intdiv($o->balance, 100),
                'next_due' => $next ? $next->due_date->format('j M Y') : '',
                'overdue' => intdiv((int) $plan->where('status', InstalmentStatus::Overdue)->sum(fn (Instalment $i) => $i->remaining()), 100),
                'status' => $o->status->label(),
            ];
        })->values()->all();

        $columns = [
            ['key' => 'order', 'label' => 'Order'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'car', 'label' => 'Car'],
            ['key' => 'total', 'label' => 'Price', 'money' => true],
            ['key' => 'paid', 'label' => 'Paid', 'money' => true],
            ['key' => 'balance', 'label' => 'Balance', 'money' => true],
            ['key' => 'next_due', 'label' => 'Next instalment'],
            ['key' => 'overdue', 'label' => 'Overdue', 'money' => true],
            ['key' => 'status', 'label' => 'Status'],
        ];

        return ['title' => 'Outstanding balances', 'columns' => $columns, 'rows' => $rows, 'totals' => self::totals($rows, $columns)];
    }

    /** Walk-ins by where the customer came from, and how many went on to order. @return Report */
    public static function walkIns(Lot $lot, string $period): array
    {
        [$from, $to] = self::range($lot, $period);
        $visits = WalkIn::query()->whereBetween('visited_at', [$from, $to])->get(['lot_customer_id', 'visited_at']);
        $customers = LotCustomer::withTrashed()->whereIn('id', $visits->pluck('lot_customer_id')->unique())->pluck('source', 'id');
        // A walk-in converts when that customer has an order created after their first visit in the period.
        $firstVisit = $visits->groupBy('lot_customer_id')->map(fn ($v) => $v->min('visited_at'));
        $ordered = SalesOrder::query()->whereIn('lot_customer_id', $firstVisit->keys())->where('status', '!=', OrderStatus::Cancelled)->get(['lot_customer_id', 'created_at'])
            ->filter(fn ($o) => $o->created_at >= $firstVisit[$o->lot_customer_id])->pluck('lot_customer_id')->unique()->flip();

        $rows = [];
        foreach (CustomerSource::cases() as $source) {
            $ids = $customers->filter(fn ($s) => ($s instanceof CustomerSource ? $s : CustomerSource::tryFrom((string) $s)) === $source)->keys();
            if ($ids->isEmpty()) {
                continue;
            }
            $count = $visits->whereIn('lot_customer_id', $ids)->count();
            $people = $ids->count();
            $bought = $ids->filter(fn ($id) => $ordered->has($id))->count();
            $rows[] = ['source' => $source->label(), 'walk_ins' => $count, 'customers' => $people, 'orders' => $bought, 'conversion' => $people ? round($bought / $people * 100, 1) : 0];
        }
        usort($rows, fn ($a, $b) => $b['walk_ins'] <=> $a['walk_ins']);

        $columns = [
            ['key' => 'source', 'label' => 'Came from'],
            ['key' => 'walk_ins', 'label' => 'Walk-ins'],
            ['key' => 'customers', 'label' => 'Customers'],
            ['key' => 'orders', 'label' => 'Ordered'],
            ['key' => 'conversion', 'label' => 'Conversion', 'percent' => true],
        ];
        $totals = self::totals($rows, $columns);
        if ($totals !== null) {
            $totals['conversion'] = $totals['customers'] ? round($totals['orders'] / $totals['customers'] * 100, 1) : 0;
        }

        return ['title' => 'Walk-ins by source', 'columns' => $columns, 'rows' => $rows, 'totals' => $totals];
    }

    /** @return Report */
    public static function staff(Lot $lot, string $period): array
    {
        [$from, $to] = self::range($lot, $period);
        $walkIns = WalkIn::query()->whereBetween('visited_at', [$from, $to])->selectRaw('staff_id, count(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');
        $orders = SalesOrder::query()->whereBetween('created_at', [$from, $to])->where('status', '!=', OrderStatus::Cancelled)->get(['staff_id', 'status', 'agreed_price', 'discount']);
        $delivered = SalesOrder::query()->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$from, $to])->get(['staff_id', 'agreed_price', 'discount']);
        $payments = OrderPayment::query()->where('lot_id', $lot->id)->whereNull('voided_at')->where('amount', '>', 0)->whereBetween('paid_at', [$from, $to])
            ->selectRaw('received_by, sum(amount) as total')->groupBy('received_by')->pluck('total', 'received_by');

        $rows = LotMember::query()->where('lot_id', $lot->id)->with('user')->get()->map(fn (LotMember $m) => [
            'name' => $m->user->name ?? $m->user->phone,
            'role' => $m->role->label(),
            'walk_ins' => (int) ($walkIns[$m->user_id] ?? 0),
            'orders' => $orders->where('staff_id', $m->user_id)->count(),
            'sold' => $delivered->where('staff_id', $m->user_id)->count(),
            'sales' => intdiv((int) $delivered->where('staff_id', $m->user_id)->sum(fn ($o) => $o->agreed_price - $o->discount), 100),
            'collected' => intdiv((int) ($payments[$m->user_id] ?? 0), 100),
        ])->sortByDesc('sales')->values()->all();

        $columns = [
            ['key' => 'name', 'label' => 'Staff'],
            ['key' => 'role', 'label' => 'Role'],
            ['key' => 'walk_ins', 'label' => 'Walk-ins logged'],
            ['key' => 'orders', 'label' => 'Orders opened'],
            ['key' => 'sold', 'label' => 'Cars sold'],
            ['key' => 'sales', 'label' => 'Sales', 'money' => true],
            ['key' => 'collected', 'label' => 'Money collected', 'money' => true],
        ];

        return ['title' => 'Staff performance', 'columns' => $columns, 'rows' => $rows, 'totals' => self::totals($rows, $columns)];
    }

    /**
     * Sums the numeric columns; the first column reads "Total".
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{key: string, label: string, money?: bool, percent?: bool}>  $columns
     * @return array<string, mixed>|null
     */
    private static function totals(array $rows, array $columns): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = [];
        foreach ($columns as $i => $c) {
            $numeric = is_int($rows[0][$c['key']] ?? null) && ! ($c['percent'] ?? false);
            $totals[$c['key']] = $i === 0 ? 'Total' : ($numeric ? array_sum(array_column($rows, $c['key'])) : '');
        }

        return $totals;
    }
}
