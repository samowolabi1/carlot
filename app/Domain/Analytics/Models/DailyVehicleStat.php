<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A car's numbers for one day in the lot's timezone (TDD M15: RollupDailyStats).
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $lot_id
 * @property Carbon $date
 * @property int $views
 * @property int $saves
 * @property int $shares
 * @property int $leads
 * @property int $bookings
 */
class DailyVehicleStat extends Model
{
    use BelongsToLot;

    public $timestamps = false;

    protected $fillable = ['vehicle_id', 'lot_id', 'date', 'views', 'saves', 'shares', 'leads', 'bookings'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'views' => 'integer', 'saves' => 'integer', 'shares' => 'integer', 'leads' => 'integer', 'bookings' => 'integer'];
    }
}
