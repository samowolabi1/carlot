<?php

namespace App\Domain\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One view, save, share, lead or booking (TDD M15). Raw rows are kept 90 days and rolled up
 * into daily_vehicle_stats; dashboards read the rollups.
 *
 * @property int $id
 * @property int $lot_id
 * @property int|null $vehicle_id
 * @property string $type
 * @property string|null $channel
 * @property Carbon $occurred_at
 */
class AnalyticsEvent extends Model
{
    public const TYPES = ['view', 'save', 'share', 'lead', 'booking'];

    public $timestamps = false;

    protected $fillable = ['lot_id', 'vehicle_id', 'type', 'channel', 'occurred_at'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
