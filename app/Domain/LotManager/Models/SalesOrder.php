<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sale in progress or done: a customer, a car, the agreed money and its payments.
 * From S5 every sale (walk-in or online) is one of these (TDD M13).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property string $order_no
 * @property int $lot_customer_id
 * @property int $vehicle_id
 * @property int|null $staff_id
 * @property int $list_price
 * @property int $agreed_price
 * @property int $discount
 * @property int|null $trade_in_id
 * @property int $trade_in_value
 * @property int|null $reservation_id
 * @property int $deposit_required
 * @property int $total_paid
 * @property int $balance
 * @property string $currency
 * @property string $payment_plan
 * @property OrderStatus $status
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property string|null $notes
 * @property string|null $client_uuid
 * @property string $payment_plan full or instalments
 * @property int|null $instalments_from_paid total_paid when the plan was set
 */
class SalesOrder extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    protected $fillable = [
        'lot_id', 'order_no', 'lot_customer_id', 'vehicle_id', 'staff_id', 'list_price', 'agreed_price', 'discount',
        'trade_in_id', 'trade_in_value', 'reservation_id', 'deposit_required', 'total_paid', 'balance', 'currency', 'payment_plan', 'status', 'notes', 'client_uuid',
    ];

    protected $hidden = ['id', 'lot_id', 'lot_customer_id', 'vehicle_id', 'staff_id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'list_price' => 'integer',
            'agreed_price' => 'integer',
            'discount' => 'integer',
            'trade_in_value' => 'integer',
            'deposit_required' => 'integer',
            'total_paid' => 'integer',
            'balance' => 'integer',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<LotCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LotCustomer::class, 'lot_customer_id')->withTrashed();
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** What the customer owes in total: agreed price less discount and trade-in. */
    /** @return HasMany<Instalment, $this> */
    public function instalments(): HasMany
    {
        return $this->hasMany(Instalment::class)->orderBy('sequence');
    }

    /**
     * Scoped route bindings for {document}.
     *
     * @return HasMany<OrderDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class)->orderBy('id');
    }

    public function total(): int
    {
        return max(0, $this->agreed_price - $this->discount - $this->trade_in_value);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, OrderStatus::open(), true);
    }

    public function money(?int $minor): string
    {
        return (string) Money::format($minor ?? 0, $this->currency);
    }
}
