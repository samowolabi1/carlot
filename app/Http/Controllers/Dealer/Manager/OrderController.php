<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Actions\CancelOrder;
use App\Domain\LotManager\Actions\ChangeOrderStatus;
use App\Domain\LotManager\Actions\CreateOrder;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\OrderRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Orders (TDD M19, M13): one record per sale, walk-in or online. */
class OrderController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', ...array_map(fn ($s) => $s->value, OrderStatus::cases())])],
        ]);
        $status = $filters['status'] ?? 'open';

        $orders = SalesOrder::query()->with(['customer', 'vehicle.make', 'vehicle.model'])
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('status', OrderStatus::open()))
            ->when($status !== 'open', fn (Builder $q) => $q->where('status', $status))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        $counts = SalesOrder::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $limit = ($lot->plan ?? Plan::default())?->limit('open_orders');

        return Inertia::render('Dealer/Manager/Orders', [
            'orders' => $orders->through(fn (SalesOrder $o) => Presenter::order($o, $lot->timezone)),
            'filters' => ['status' => $status],
            'counts' => [
                'open' => collect(OrderStatus::open())->sum(fn (OrderStatus $s) => (int) ($counts[$s->value] ?? 0)),
                'delivered' => (int) ($counts[OrderStatus::Delivered->value] ?? 0),
                'cancelled' => (int) ($counts[OrderStatus::Cancelled->value] ?? 0),
            ],
            'limit' => $limit,
        ]);
    }

    /** New order; "Mark sold" on the stock list opens this with the car picked. */
    public function create(Request $request, Lot $lot): Response
    {
        $customer = $request->filled('customer') ? LotCustomer::query()->where('ulid', $request->string('customer'))->first() : null;

        return Inertia::render('Dealer/Manager/OrderCreate', [
            'stock' => Presenter::stock(),
            'vehicle' => $request->string('vehicle')->toString() ?: null,
            'customer' => $customer ? Presenter::customer($customer) : null,
            'customers' => LotCustomer::query()->orderByDesc('last_seen_at')->limit(300)->get()
                ->map(fn (LotCustomer $c) => ['ulid' => $c->ulid, 'name' => $c->name, 'phone_display' => PhoneNumber::display($c->phone)]),
        ]);
    }

    public function store(OrderRequest $request, Lot $lot, CreateOrder $create): RedirectResponse
    {
        $order = $create->run($lot, $request->user(), $request->action());

        return to_route('dealer.manager.orders.show', [$lot, $order])->with('success', "Order {$order->order_no} created. Record the deposit when it comes in.");
    }

    public function show(Request $request, Lot $lot, SalesOrder $order): Response
    {
        Gate::authorize('view', $order);

        $order->load(['customer', 'vehicle.make', 'vehicle.model', 'staff', 'payments.receiver']);
        $user = $request->user();
        $track = OrderLinks::track($order);

        return Inertia::render('Dealer/Manager/Order', [
            'order' => [
                ...Presenter::order($order, $lot->timezone),
                'list_price' => $order->money($order->list_price),
                'agreed_price' => $order->money($order->agreed_price),
                'discount' => $order->discount > 0 ? $order->money($order->discount) : null,
                'trade_in' => $order->trade_in_value > 0 ? $order->money($order->trade_in_value) : null,
                'deposit_required' => $order->deposit_required > 0 ? $order->money($order->deposit_required) : null,
                'balance_major' => intdiv(max(0, $order->balance), 100),
                'staff' => $order->staff?->name,
                'notes' => $order->notes,
                'cancelled_reason' => $order->cancelled_reason,
                'delivered' => $order->delivered_at?->copy()->setTimezone($lot->timezone)->format('j M Y'),
                'vehicle_ulid' => $order->vehicle?->ulid,
                'track_url' => $track,
            ],
            'customer' => $order->customer ? Presenter::customer($order->customer) : null,
            'payments' => $order->payments->map(fn (OrderPayment $p) => [
                ...Presenter::payment($p, $lot->timezone, $order),
                'receipt_url' => route('dealer.manager.orders.receipt', [$lot, $order, $p]),
            ]),
            'steps' => collect([OrderStatus::Draft, OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady, OrderStatus::Delivered])
                ->map(fn (OrderStatus $s) => ['value' => $s->value, 'label' => $s->label(), 'done' => $order->status !== OrderStatus::Cancelled && $order->status->rank() >= $s->rank()]),
            'can' => [
                'pay' => $order->isOpen() && $order->balance > 0 && $user->can('recordPayment', $order),
                'papers' => in_array($order->status, [OrderStatus::DepositPaid, OrderStatus::FullyPaid], true),
                'deliver' => in_array($order->status, [OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady], true)
                    && ($order->balance <= 0 || $user->hasLotRole($lot, LotRole::Owner)),
                'void' => $order->isOpen() && $user->can('voidPayment', $order),
                'cancel' => $order->isOpen() && $user->can('cancel', $order),
            ],
            'methods' => array_values(array_filter(PaymentMethod::options(), fn ($o) => $o['value'] !== PaymentMethod::Paystack->value)),
            'share' => $order->customer ? 'https://wa.me/'.ltrim($order->customer->phone, '+').'?text='.rawurlencode("Hi {$order->customer->name}, you can track your order {$order->order_no} with {$lot->name} here: {$track}") : null,
        ]);
    }

    public function update(Request $request, Lot $lot, SalesOrder $order, ChangeOrderStatus $change): RedirectResponse
    {
        Gate::authorize('changeStatus', $order);

        $data = $request->validate([
            'status' => ['required', Rule::in([OrderStatus::PapersReady->value, OrderStatus::Delivered->value])],
            'override_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $change->run($order, $request->user(), OrderStatus::from($data['status']), $data['override_reason'] ?? null);

        return back()->with('success', $data['status'] === OrderStatus::Delivered->value ? 'Delivered. The car is marked sold.' : 'Papers marked ready.');
    }

    public function cancel(Request $request, Lot $lot, SalesOrder $order, CancelOrder $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $order);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'money' => ['nullable', Rule::in([CancelOrder::REFUND, CancelOrder::CREDIT])],
            'refund_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $cancel->run($order, $request->user(), $data['reason'], $data['money'] ?? null, $data['refund_method'] ?? null);

        return back()->with('success', 'Order cancelled. The car is back in stock.');
    }
}
