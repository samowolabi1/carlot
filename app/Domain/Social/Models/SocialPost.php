<?php

namespace App\Domain\Social\Models;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One listing posted (or not) to one social account; failures keep their reason.
 *
 * @property int $id
 * @property int $lot_id
 * @property int $vehicle_id
 * @property int $social_account_id
 * @property string|null $external_id
 * @property string $status queued, posted, failed
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $posted_at
 * @property Carbon $created_at
 */
class SocialPost extends Model
{
    use BelongsToLot, StoresUtc;

    protected $fillable = ['lot_id', 'vehicle_id', 'social_account_id', 'external_id', 'status', 'error', 'attempts', 'posted_at'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScopes();
    }
}
