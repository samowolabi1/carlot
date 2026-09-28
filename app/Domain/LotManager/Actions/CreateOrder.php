<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\CustomerBook;
use App\Domain\LotManager\Support\LotCounter;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    /**
     * An order for an available or reserved car. The price defaults to the list price;
     * discount and trade-in reduce the balance (TDD M19: Orders). Amounts in minor units.
     *
     * @param  array{customer?: ?string, name?: ?string, phone?: ?string, email?: ?string, consent_whatsapp?: ?bool, vehicle: string, agreed_price?: ?int, discount?: ?int, trade_in_value?: ?int, deposit_required?: ?int, notes?: ?string, client_uuid?: ?string}  $data
     */
    public function run(Lot $lot, User $staff, array $data): SalesOrder
    {
        if (filled($data['client_uuid'] ?? null) && ($existing = $this->find($lot, $data['client_uuid']))) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($lot, $staff, $data): SalesOrder {
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

                $list = (int) ($vehicle->price ?? 0);
                $agreed = $data['agreed_price'] ?? $list;
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
                    'trade_in_value' => $data['trade_in_value'] ?? 0,
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

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            return $this->find($lot, (string) ($data['client_uuid'] ?? '')) ?? throw $e;
        }
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
