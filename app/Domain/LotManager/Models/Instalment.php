<?php

namespace App\Domain\LotManager\Models;

use App\Domain\LotManager\Enums\InstalmentStatus;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled amount in an order's instalment plan (TDD M19). The seller's own arrangement:
 * CarYard only records and reminds. paid_amount and status come from InstalmentSchedule.
 *
 * @property int $id
 * @property int $lot_id
 * @property int $sales_order_id
 * @property int $sequence
 * @property Carbon $due_date
 * @property int $amount
 * @property int $paid_amount
 * @property InstalmentStatus $status
 * @property Carbon|null $reminded_before_at
 * @property Carbon|null $reminded_due_at
 */
class Instalment extends Model
{
    use StoresUtc;

    protected $fillable = ['lot_id', 'sales_order_id', 'sequence', 'due_date', 'amount', 'paid_amount', 'status'];

    protected $hidden = ['id', 'lot_id', 'sales_order_id'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'sequence' => 'integer',
            'status' => InstalmentStatus::class,
            'reminded_before_at' => 'datetime',
            'reminded_due_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SalesOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id')->withoutGlobalScope('lot');
    }

    public function remaining(): int
    {
        return max(0, $this->amount - $this->paid_amount);
    }
}
