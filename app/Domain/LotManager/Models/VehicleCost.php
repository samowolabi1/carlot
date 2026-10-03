<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\CostType;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the seller spent on a car: the purchase and every extra (TDD M19: car costs and profit).
 * Owners and managers only; never on customer pages, receipts or API resources.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $vehicle_id
 * @property CostType $type
 * @property int $amount
 * @property string $currency
 * @property string|null $supplier
 * @property string|null $note
 * @property Carbon $incurred_at
 * @property string|null $receipt_path
 * @property int|null $created_by
 */
class VehicleCost extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const DISK = 'local';

    protected $fillable = ['lot_id', 'vehicle_id', 'type', 'amount', 'currency', 'supplier', 'note', 'incurred_at', 'receipt_path', 'created_by'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'receipt_path', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => CostType::class,
            'amount' => 'integer',
            'incurred_at' => 'date',
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

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function money(): string
    {
        return (string) Money::format($this->amount, $this->currency);
    }
}
