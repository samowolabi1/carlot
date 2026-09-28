<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $vehicle_id
 * @property int $old_price
 * @property int $new_price
 * @property int|null $changed_by
 */
class VehiclePriceHistory extends Model
{
    protected $table = 'vehicle_price_history';

    protected $fillable = ['vehicle_id', 'old_price', 'new_price', 'changed_by'];

    protected function casts(): array
    {
        return ['old_price' => 'integer', 'new_price' => 'integer'];
    }
}
