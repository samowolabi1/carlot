<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Enums\Interest;
use App\Domain\LotManager\Enums\NextStep;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Support\Money;
use App\Domain\Support\Name;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Shapes Lot Manager records for the dealer pages. Walk-in customers gave their number
 * to the lot in person, so it is shown in full. Car costs never appear here (S10).
 */
final class Presenter
{
    /** @return array<string, mixed> */
    public static function customer(LotCustomer $c): array
    {
        return [
            'ulid' => $c->ulid,
            'name' => $c->name,
            'phone' => $c->phone,
            'phone_display' => PhoneNumber::display($c->phone),
            'whatsapp' => $c->phone ? ltrim($c->phone, '+') : null,
            'email' => $c->email,
            'source' => $c->source->value,
            'source_label' => $c->source->label(),
            'tags' => $c->tags ?? [],
            'budget_max' => $c->budget_max !== null ? intdiv($c->budget_max, 100) : null,
            'budget_label' => Money::format($c->budget_max),
            'notes' => $c->notes,
            'consent_whatsapp' => $c->consent_whatsapp,
            'last_seen' => $c->last_seen_at?->diffForHumans(),
            'has_account' => $c->user_id !== null,
        ];
    }

    /**
     * @param  Collection<int, Vehicle>  $vehicles  cars viewed, keyed by id
     * @return array<string, mixed>
     */
    public static function walkIn(WalkIn $w, string $tz, Collection $vehicles): array
    {
        return [
            'ulid' => $w->ulid,
            'customer' => $w->customer ? ['ulid' => $w->customer->ulid, 'name' => $w->customer->name, 'phone_display' => PhoneNumber::display($w->customer->phone)] : null,
            'time' => $w->visited_at->copy()->setTimezone($tz)->format('H:i'),
            'when' => $w->visited_at->copy()->setTimezone($tz)->format('D j M, H:i'),
            'interest' => $w->interest->value,
            'interest_label' => $w->interest->label(),
            'next_step' => $w->next_step->value,
            'next_step_label' => $w->next_step->label(),
            'cars' => collect($w->vehicles_viewed ?? [])->map(fn ($id) => $vehicles->get($id)?->title())->filter()->values(),
            'staff' => $w->staff?->name,
            'notes' => $w->notes,
        ];
    }

    /** @return array<string, mixed> */
    public static function order(SalesOrder $o, string $tz): array
    {
        return [
            'ulid' => $o->ulid,
            'order_no' => $o->order_no,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'open' => $o->isOpen(),
            'customer' => $o->customer ? ['ulid' => $o->customer->ulid, 'name' => $o->customer->name, 'phone_display' => PhoneNumber::display($o->customer->phone)] : null,
            'car' => $o->vehicle?->title(),
            'total' => $o->money($o->total()),
            'paid' => $o->money($o->total_paid),
            'balance' => $o->money(max(0, $o->balance)),
            'balance_minor' => $o->balance,
            'progress' => $o->total() > 0 ? min(100, (int) round(max(0, $o->total_paid) / $o->total() * 100)) : 0,
            'created' => $o->created_at?->copy()->setTimezone($tz)->format('j M Y'),
        ];
    }

    /** @return array<string, mixed> */
    public static function payment(OrderPayment $p, string $tz, SalesOrder $order): array
    {
        return [
            'ulid' => $p->ulid,
            'amount' => $order->money(abs($p->amount)),
            'refund' => $p->amount < 0,
            'method' => $p->method->label(),
            'reference' => $p->reference,
            'receipt_no' => $p->receipt_no,
            'when' => $p->paid_at->copy()->setTimezone($tz)->format('D j M Y, H:i'),
            'by' => $p->receiver?->name,
            'void' => $p->isVoid(),
            'void_reason' => $p->void_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function task(FollowUpTask $t, string $tz): array
    {
        $due = $t->due_at->copy()->setTimezone($tz);

        return [
            'ulid' => $t->ulid,
            'type' => $t->type->value,
            'type_label' => $t->type->label(),
            'due' => $due->isToday() ? 'Today '.$due->format('H:i') : $due->format('D j M, H:i'),
            'overdue' => $t->due_at->isPast(),
            'note' => $t->note,
            'assignee' => $t->assignee?->name,
            'customer' => $t->customer ? [
                'ulid' => $t->customer->ulid,
                'name' => $t->customer->name,
                'phone' => $t->customer->phone,
                'phone_display' => PhoneNumber::display($t->customer->phone),
                'whatsapp' => $t->customer->phone ? ltrim($t->customer->phone, '+') : null,
            ] : null,
        ];
    }

    /** Cars staff can pick in the walk-in and order forms (not sold). @return list<array<string, mixed>> */
    public static function stock(): array
    {
        // Paid reservations: the order form fills in the buyer and the price (TDD M12).
        $reservations = Reservation::query()->where('status', ReservationStatus::Active)->with('customer')->get()->keyBy('vehicle_id');
        $bookIds = Lead::query()->whereIn('id', $reservations->pluck('lead_id')->filter())->pluck('lot_customer_id', 'id');
        $book = LotCustomer::query()->whereIn('id', $bookIds->filter())->pluck('ulid', 'id');

        return Vehicle::query()->with(['make', 'model'])
            ->where('status', '!=', VehicleStatus::Sold)
            ->orderByDesc('listed_at')->orderByDesc('id')
            ->limit(300)
            ->get()
            ->map(fn (Vehicle $v) => [
                'ulid' => $v->ulid,
                'title' => $v->title() ?: 'Untitled car',
                'price' => $v->price !== null ? intdiv($v->price, 100) : null,
                'price_label' => $v->formattedPrice(),
                'status' => $v->status->value,
                'orderable' => in_array($v->status, [VehicleStatus::Available, VehicleStatus::Reserved], true),
                'reservation' => ($r = $reservations->get($v->id)) ? [
                    'by' => Name::short($r->customer->name),
                    'customer' => $book[$bookIds[$r->lead_id] ?? 0] ?? null,
                    'deposit' => $r->money(),
                    'price' => intdiv($r->price, 100),
                ] : null,
            ])->all();
    }

    /** Valued trade-ins the lot can take against an order. @return list<array<string, mixed>> */
    public static function tradeIns(): array
    {
        $tradeIns = TradeIn::query()->whereIn('status', [TradeInStatus::Valued, TradeInStatus::Accepted])
            ->whereNotIn('id', SalesOrder::query()->whereNotNull('trade_in_id')->select('trade_in_id'))
            ->with(['make', 'model', 'customer'])->latest('valued_at')->limit(100)->get();
        $bookIds = Lead::query()->whereIn('id', $tradeIns->pluck('lead_id')->filter())->pluck('lot_customer_id', 'id');
        $book = LotCustomer::query()->whereIn('id', $bookIds->filter())->pluck('ulid', 'id');

        return $tradeIns->map(fn (TradeIn $t) => [
            'ulid' => $t->ulid,
            'label' => $t->title().' · '.Name::short($t->customer->name).' · '.$t->estimate(),
            'customer' => $book[$bookIds[$t->lead_id] ?? 0] ?? null,
            'value' => $t->estimate_low !== null ? intdiv($t->estimate_low, 100) : null,
        ])->values()->all();
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function options(): array
    {
        return [
            'sources' => CustomerSource::options(),
            'interests' => Interest::options(),
            'next_steps' => NextStep::options(),
            'methods' => array_values(array_filter(PaymentMethod::options(), fn ($o) => $o['value'] !== PaymentMethod::Paystack->value)),
            'tags' => array_map(fn ($t) => ['value' => $t, 'label' => ucfirst($t)], LotCustomer::TAGS),
        ];
    }
}
