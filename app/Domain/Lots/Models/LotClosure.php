<?php

namespace App\Domain\Lots\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lot_id
 * @property Carbon $date
 * @property string|null $reason
 */
class LotClosure extends Model
{
    use BelongsToLot;

    protected $fillable = ['lot_id', 'date', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
