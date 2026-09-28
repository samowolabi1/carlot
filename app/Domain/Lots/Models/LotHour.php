<?php

namespace App\Domain\Lots\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $lot_id
 * @property int $weekday
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $is_closed
 * @property int $slot_minutes
 * @property int $slot_capacity
 */
class LotHour extends Model
{
    use BelongsToLot;

    public const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    protected $fillable = ['lot_id', 'weekday', 'opens_at', 'closes_at', 'is_closed', 'slot_minutes', 'slot_capacity'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_closed' => 'boolean',
            'slot_minutes' => 'integer',
            'slot_capacity' => 'integer',
        ];
    }
}
