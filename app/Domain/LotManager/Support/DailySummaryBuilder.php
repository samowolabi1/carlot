<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use Carbon\CarbonImmutable;

/** The numbers in the owner's evening message (TDD M19: daily summary). */
final class DailySummaryBuilder
{
    /** @return array{date: string, walk_ins: int, orders: int, received: string, by_method: string, balances: string, overdue: int} */
    public static function for(Lot $lot, CarbonImmutable $day): array
    {
        $from = $day->setTimezone($lot->timezone)->startOfDay()->utc();
        $to = $day->setTimezone($lot->timezone)->endOfDay()->utc();
        $currency = (string) config('lotlink.currency', 'NGN');

        $payments = OrderPayment::query()->where('lot_id', $lot->id)->whereNull('voided_at')->whereBetween('paid_at', [$from, $to])->get(['amount', 'method']);
        $byMethod = $payments->groupBy(fn (OrderPayment $p) => $p->method->label())
            ->map(fn ($group, $method) => $method.' '.Money::compact((int) $group->sum('amount'), $currency))->values()->implode(', ');
        $open = SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->whereIn('status', OrderStatus::open());

        return [
            'date' => $day->setTimezone($lot->timezone)->format('D j M'),
            'walk_ins' => WalkIn::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('visited_at', [$from, $to])->count(),
            'orders' => SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->whereBetween('created_at', [$from, $to])->count(),
            'received' => (string) Money::format((int) $payments->sum('amount'), $currency),
            'by_method' => $byMethod,
            'balances' => (string) Money::format((int) (clone $open)->where('balance', '>', 0)->sum('balance'), $currency),
            'overdue' => Instalment::query()->where('lot_id', $lot->id)->where('status', InstalmentStatus::Overdue)
                ->whereIn('sales_order_id', (clone $open)->select('id'))->count(),
        ];
    }
}
