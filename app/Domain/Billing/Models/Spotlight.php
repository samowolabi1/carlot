<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paid (or free-allowance) boost: a car at the top of search and in the home carousel,
 * or the lot in "Featured lots" (TDD M5).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $vehicle_id
 * @property SpotlightPlacement $placement
 * @property int $days
 * @property string $status
 * @property bool $free
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $payment_id
 * @property int|null $bought_by
 * @property Carbon|null $created_at
 */
class Spotlight extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const DAYS = [7, 14, 30];

    protected $fillable = ['lot_id', 'vehicle_id', 'placement', 'days', 'status', 'free', 'starts_at', 'ends_at', 'payment_id', 'bought_by'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'payment_id', 'bought_by'];

    protected function casts(): array
    {
        return [
            'placement' => SpotlightPlacement::class,
            'days' => 'integer',
            'free' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
