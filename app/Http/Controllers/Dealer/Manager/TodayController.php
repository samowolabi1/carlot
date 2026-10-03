<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Sales Manager home: today's walk-ins, follow-ups due and balances to collect. */
class TodayController extends Controller
{
    public function __invoke(Request $request, Lot $lot): Response
    {
        $tz = $lot->timezone;
        $start = CarbonImmutable::now($tz)->startOfDay();
        $end = $start->addDay();

        $walkIns = WalkIn::query()->with(['customer', 'staff'])
            ->whereBetween('visited_at', [$start->utc(), $end->utc()])
            ->latest('visited_at')->get();
        $cars = Vehicle::query()->with(['make', 'model'])->whereIn('id', $walkIns->pluck('vehicles_viewed')->flatten()->unique()->all())->get()->keyBy('id');

        $tasks = FollowUpTask::query()->open()->with(['customer', 'assignee'])
            ->where('due_at', '<', $end->utc())
            ->orderBy('due_at')->limit(50)->get();

        $balances = SalesOrder::query()->with(['customer', 'vehicle.make', 'vehicle.model'])
            ->whereIn('status', OrderStatus::open())
            ->where('balance', '>', 0)
            ->orderByDesc('balance')->limit(10)->get();

        // TDD M19: overdue instalments show on the owner's day.
        $overdue = Instalment::query()->where('lot_id', $lot->id)->where('status', InstalmentStatus::Overdue)
            ->whereIn('sales_order_id', SalesOrder::query()->whereIn('status', OrderStatus::open())->select('id'))
            ->with(['order.customer', 'order.vehicle.make', 'order.vehicle.model'])->orderBy('due_date')->limit(20)->get();

        $received = (int) OrderPayment::query()->where('lot_id', $lot->id)->whereNull('voided_at')
            ->where('amount', '>', 0)
            ->whereBetween('paid_at', [$start->utc(), $end->utc()])
            ->sum('amount');

        return Inertia::render('Dealer/Manager/Today', [
            'date' => $start->format('l j F'),
            'stats' => [
                'walk_ins' => $walkIns->count(),
                'received' => Money::format($received, config('lotlink.currency')),
                'open_orders' => SalesOrder::query()->whereIn('status', OrderStatus::open())->count(),
                'outstanding' => Money::format((int) SalesOrder::query()->whereIn('status', OrderStatus::open())->where('balance', '>', 0)->sum('balance'), config('lotlink.currency')),
            ],
            'walkIns' => $walkIns->map(fn (WalkIn $w) => Presenter::walkIn($w, $tz, $cars)),
            'tasks' => $tasks->map(fn (FollowUpTask $t) => Presenter::task($t, $tz)),
            'balances' => $balances->map(fn (SalesOrder $o) => Presenter::order($o, $tz)),
            'overdue' => $overdue->map(fn (Instalment $i) => [
                'order_ulid' => $i->order->ulid,
                'order_no' => $i->order->order_no,
                'customer' => $i->order->customer->name ?? '',
                'car' => $i->order->vehicle?->title(),
                'amount' => $i->order->money($i->remaining()),
                'due' => $i->due_date->format('j M'),
            ]),
            'stock' => Presenter::stock(),
            'options' => Presenter::options(),
        ]);
    }
}
