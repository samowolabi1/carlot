<?php

namespace App\Domain\Lots\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Model;

class LotClosure extends Model
{
    use BelongsToLot;

    protected $fillable = ['lot_id', 'date', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
