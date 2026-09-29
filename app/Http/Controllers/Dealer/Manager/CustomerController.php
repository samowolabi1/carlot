<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\CustomerRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** The customer book: one row per person, with a timeline of everything at this lot. */
class CustomerController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'tag' => ['nullable', 'string', 'in:'.implode(',', LotCustomer::TAGS)],
        ]);

        $customers = LotCustomer::query()
            ->withCount(['walkIns', 'orders'])
            ->when($filters['q'] ?? null, function (Builder $q, string $term): void {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
                    ->when($digits !== '', fn (Builder $q) => $q->orWhere('phone', 'like', '%'.ltrim((string) $digits, '0').'%')));
            })
            ->when($filters['tag'] ?? null, fn (Builder $q, string $tag) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Dealer/Manager/Customers', [
            'customers' => $customers->through(fn (LotCustomer $c) => [
                ...Presenter::customer($c),
                'visits' => $c->walk_ins_count,
                'orders' => $c->orders_count,
            ]),
            'filters' => ['q' => $filters['q'] ?? '', 'tag' => $filters['tag'] ?? null],
            'total' => LotCustomer::query()->count(),
            'options' => Presenter::options(),
        ]);
    }

    public function show(Lot $lot, LotCustomer $customer): Response
    {
        $tz = $lot->timezone;
        $at = fn (Carbon $time) => $time->copy()->setTimezone($tz);

        $walkIns = $customer->walkIns()->with('staff')->get();
        $orders = $customer->orders()->with(['vehicle.make', 'vehicle.model', 'customer'])->latest()->get();
        $payments = OrderPayment::query()->whereIn('sales_order_id', $orders->pluck('id'))->get();
        $tasks = $customer->tasks()->with('assignee')->get();
        $cars = Vehicle::query()->with(['make', 'model'])->whereIn('id', $walkIns->pluck('vehicles_viewed')->flatten()->unique()->all())->get()->keyBy('id');

        // Marketplace bookings by the same phone number (the buyer's LotLink account).
        $userIds = User::query()->where(fn ($q) => $q->when($customer->phone, fn ($q) => $q->where('phone', $customer->phone))
            ->when($customer->email, fn ($q) => $q->orWhere('email', $customer->email)))
            ->when(! $customer->phone && ! $customer->email, fn ($q) => $q->whereRaw('1 = 0'))->pluck('id');
        $bookings = $userIds->isEmpty() ? collect() : Appointment::query()->with(['vehicle.make', 'vehicle.model'])->whereIn('customer_id', $userIds)->get();

        $ordersById = $orders->keyBy('id');

        $timeline = collect()
            ->concat($walkIns->map(fn (WalkIn $w) => [
                'kind' => 'walk_in', 'at' => $w->visited_at,
                'title' => 'Visited the lot',
                'detail' => collect([$w->interest->label(), collect($w->vehicles_viewed ?? [])->map(fn ($id) => $cars->get($id)?->title())->filter()->implode(', '), $w->notes])->filter()->implode(' · '),
                'by' => $w->staff?->name,
            ]))
            ->concat($orders->map(fn (SalesOrder $o) => [
                'kind' => 'order', 'at' => $o->created_at,
                'title' => "Order {$o->order_no}",
                'detail' => collect([$o->vehicle?->title(), $o->money($o->total()), $o->status->label()])->filter()->implode(' · '),
                'href' => route('dealer.manager.orders.show', [$lot, $o]),
            ]))
            ->concat($payments->map(fn (OrderPayment $p) => [
                'kind' => 'payment', 'at' => $p->paid_at,
                'title' => ($p->amount < 0 ? 'Refunded ' : 'Paid ').$ordersById->get($p->sales_order_id)?->money(abs($p->amount)).($p->isVoid() ? ' (void)' : ''),
                'detail' => collect([$p->method->label(), $p->receipt_no])->filter()->implode(' · '),
            ]))
            ->concat($tasks->map(fn (FollowUpTask $t) => [
                'kind' => 'task', 'at' => $t->due_at,
                'title' => $t->type->label().($t->done_at ? ' (done)' : ''),
                'detail' => $t->note ?? '',
                'by' => $t->assignee?->name,
            ]))
            ->concat($bookings->map(fn (Appointment $a) => [
                'kind' => 'booking', 'at' => $a->starts_at,
                'title' => $a->type->label().' booked on LotLink',
                'detail' => collect([$a->vehicle?->title(), $a->status->label()])->filter()->implode(' · '),
            ]))
            ->sortByDesc(fn ($item) => $item['at']->getTimestamp())
            ->values()
            ->map(fn ($item) => [...$item, 'at' => $at($item['at'])->format('D j M Y, H:i')]);

        return Inertia::render('Dealer/Manager/Customer', [
            'customer' => Presenter::customer($customer),
            'orders' => $orders->map(fn (SalesOrder $o) => Presenter::order($o, $tz)),
            'timeline' => $timeline,
            'stats' => [
                'visits' => $walkIns->count(),
                'orders' => $orders->count(),
                'paid' => Money::format((int) $payments->whereNull('voided_at')->sum('amount'), config('lotlink.currency')),
            ],
            'options' => Presenter::options(),
        ]);
    }

    public function update(CustomerRequest $request, Lot $lot, LotCustomer $customer): RedirectResponse
    {
        $data = $request->validated();
        $customer->fill([...$data, 'budget_max' => isset($data['budget_max']) ? Money::fromMajor($data['budget_max']) : null, 'tags' => $data['tags'] ?? []])->save();

        return back()->with('success', 'Customer saved.');
    }
}
