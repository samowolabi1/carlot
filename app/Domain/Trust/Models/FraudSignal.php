<?php

namespace App\Domain\Trust\Models;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Enums\FraudSignalType;
use App\Domain\Trust\Enums\SignalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something about a listing a person should look at (TDD M14). Admin-only; signals never block.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $lot_id
 * @property FraudSignalType $type
 * @property int|null $related_vehicle_id
 * @property array<string, mixed>|null $details
 * @property SignalStatus $status
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon $created_at
 */
class FraudSignal extends Model
{
    use StoresUtc;

    protected $fillable = ['vehicle_id', 'lot_id', 'type', 'related_vehicle_id', 'details', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return [
            'type' => FraudSignalType::class,
            'status' => SignalStatus::class,
            'details' => 'array',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'related_vehicle_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** "Same VIN as a Prime Motors listing" (review queue design A1). */
    public function label(): string
    {
        $d = $this->details ?? [];

        return match ($this->type) {
            FraudSignalType::DuplicateVin => 'Same VIN as a '.($d['other_lot'] ?? 'another lot\'s').' listing',
            FraudSignalType::DuplicatePhoto => 'Cover photo matches '.(isset($d['other_lot']) ? $d['other_lot'].'\'s' : 'another lot\'s'),
            FraudSignalType::LowPrice => 'Price '.($d['below_percent'] ?? '?').'% below guide',
            FraudSignalType::ListingBurst => ($d['count'] ?? '30+').' cars in 24 h',
        };
    }
}
