<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\CustomerBook;
use App\Domain\LotManager\Support\LotCounter;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Name;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    public function __construct(private readonly RecordPayment $payments) {}

    /**
     * An order for an available or reserved car. The price defaults to the list price;
     * discount and trade-in reduce the balance (TDD M19: Orders). Amounts in minor units.
     *
     * @param  array{customer?: ?string, name?: ?string, phone?: ?string, email?: ?string, consent_whatsapp?: ?bool, vehicle: string, agreed_price?: ?int, discount?: ?int, trade_in_value?: ?int, trade_in?: ?string, deposit_required?: ?int, notes?: ?string, client_uuid?: ?string}  $data
     */
    public function run(Lot $lot, User $staff, array $data): SalesOrder
    {
        if (filled($data['client_uuid'] ?? null) && ($existing = $this->find($lot, $data['client_uuid']))) {
            return $existing;
        }

        try {
            $order = DB::transaction(function () use ($lot, $staff, $data): SalesOrder {
                // Serialises order creation per lot for the plan limit and the car check.
                Lot::whereKey($lot->id)->lockForUpdate()->first();

                $this->checkPlanLimit($lot);

                $vehicle = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('ulid', $data['vehicle'])->first()
                    ?? throw ValidationException::withMessages(['vehicle' => 'Pick a car from your stock.']);

                if (! in_array($vehicle->status, [VehicleStatus::Available, VehicleStatus::Reserved], true)) {
                    throw ValidationException::withMessages(['vehicle' => 'Only an available or reserved car can be ordered. List it on LotLink first.']);
                }

                $held = SalesOrder::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->whereIn('status', OrderStatus::open())->value('order_no');
                if ($held !== null) {
                    throw ValidationException::withMessages(['vehicle' => "This car is already on order {$held}."]);
                }

                $customer = filled($data['customer'] ?? null)
                    ? LotCustomer::withoutGlobalScopes()->where('lot_id', $lot->id)->where('ulid', $data['customer'])->first()
                        ?? throw ValidationException::withMessages(['customer' => 'That customer is not in your book.'])
                    : CustomerBook::match($lot, [...$data, 'phone' => (string) ($data['phone'] ?? '')], now());

                // A paid reservation belongs to one buyer: their order converts it (TDD M12).
                $reservation = Reservation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)
                    ->where('status', ReservationStatus::Active)->lockForUpdate()->first();
                if ($reservation !== null && ! $this->isReservationHolder($reservation, $customer)) {
                    throw ValidationException::withMessages(['vehicle' => 'This car is reserved for '.Name::short($reservation->customer->name).' until '
                        .$reservation->expires_at?->copy()->setTimezone($lot->timezone)->format('D j M, g:ia').'. Cancel the reservation first.']);
                }

                $tradeIn = null;
                if (filled($data['trade_in'] ?? null)) {
                    $tradeIn = TradeIn::withoutGlobalScopes()->where('lot_id', $lot->id)->where('ulid', $data['trade_in'])
                        ->whereIn('status', [TradeInStatus::Valued, TradeInStatus::Accepted])->first()
                        ?? throw ValidationException::withMessages(['trade_in' => 'Pick a valued trade-in.']);
                    $tradeIn->forceFill(['status' => TradeInStatus::Accepted])->save();
                }

                $list = (int) ($vehicle->price ?? 0);
                $agreed = $data['agreed_price'] ?? $reservation->price ?? $list;
                if ($agreed <= 0) {
                    throw ValidationException::withMessages(['agreed_price' => 'Enter the agreed price.']);
                }

                $order = SalesOrder::withoutGlobalScopes()->make([
                    'lot_id' => $lot->id,
                    'order_no' => LotCounter::next($lot, 'order'),
                    'lot_customer_id' => $customer->id,
                    'vehicle_id' => $vehicle->id,
                    'staff_id' => $staff->id,
                    'list_price' => $list,
                    'agreed_price' => $agreed,
                    'discount' => $data['discount'] ?? 0,
                    'trade_in_id' => $tradeIn?->id,
                    'trade_in_value' => $data['trade_in_value'] ?? $tradeIn->estimate_low ?? 0,
                    'reservation_id' => $reservation?->id,
                    'deposit_required' => $data['deposit_required'] ?? 0,
                    'total_paid' => 0,
                    'currency' => $vehicle->currency,
                    'payment_plan' => 'full',
                    'status' => OrderStatus::Draft,
                    'notes' => $data['notes'] ?? null,
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);
                $order->balance = $order->total();
                $order->save();

                AuditLog::record('order.created', $order, ['order_no' => $order->order_no, 'agreed_price' => $agreed], $staff);

                $reservation?->forceFill(['status' => ReservationStatus::Converted, 'ended_at' => now(), 'end_reason' => "Order {$order->order_no}", 'sales_order_id' => $order->id])->save();

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($lot, (string) ($data['client_uuid'] ?? '')) ?? throw $e;
        }

        // The reservation deposit counts towards the price, as a Paystack payment on the order.
        if ($order->wasRecentlyCreated && $order->reservation_id !== null && $order->balance > 0) {
            $reservation = Reservation::withoutGlobalScopes()->findOrFail($order->reservation_id);
            $this->payments->run($order, $staff, [
                'amount' => min($reservation->amount, max(0, $order->balance)),
                'method' => PaymentMethod::Paystack->value,
                'reference' => $reservation->payment?->reference,
                'paid_at' => $reservation->activated_at?->toIso8601String(),
            ]);
            $order->refresh();
        }

        return $order;
    }

    /** The order's customer is the buyer who paid: same phone, or the same customer-book entry. */
    private function isReservationHolder(Reservation $reservation, LotCustomer $customer): bool
    {
        $buyer = $reservation->customer;
        $leadCustomer = $reservation->lead_id ? Lead::withoutGlobalScopes()->whereKey($reservation->lead_id)->value('lot_customer_id') : null;

        return $leadCustomer === $customer->id || $buyer->phone === $customer->phone;
    }

    private function find(Lot $lot, string $clientUuid): ?SalesOrder
    {
        return SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->where('client_uuid', $clientUuid)->first();
    }

    private function checkPlanLimit(Lot $lot): void
    {
        $limit = ($lot->plan ?? Plan::default())?->limit('open_orders');

        if ($limit === null) {
            return;
        }

        $open = SalesOrder::withoutGlobalScopes()->where('lot_id', $lot->id)->whereIn('status', OrderStatus::open())->count();

        if ($open >= $limit) {
            throw ValidationException::withMessages(['vehicle' => "Your plan allows {$limit} open orders. Deliver or cancel one, or upgrade your plan."]);
        }
    }
}
