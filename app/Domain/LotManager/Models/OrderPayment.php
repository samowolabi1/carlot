<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\PaymentMethod;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received (or refunded, as a negative amount) against an order. Never deleted: a
 * mistake is voided with a reason (TDD M19).
 *
 * @property int $id
 * @property string $ulid
 * @property int $sales_order_id
 * @property int $lot_id
 * @property int $amount
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property int|null $received_by
 * @property Carbon $paid_at
 * @property string|null $receipt_no
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $client_uuid
 */
class OrderPayment extends Model
{
    use HasUlids, StoresUtc;

    protected $fillable = ['sales_order_id', 'lot_id', 'amount', 'method', 'reference', 'received_by', 'paid_at', 'receipt_no', 'client_uuid'];

    protected $hidden = ['id', 'sales_order_id', 'lot_id', 'received_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
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

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id')->withoutGlobalScope('lot');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }
}
