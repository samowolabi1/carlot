<?php

namespace App\Domain\Lots\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $price
 * @property string $currency
 * @property int|null $listing_limit
 * @property int|null $staff_limit
 * @property int $free_spotlights
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price', 'currency', 'interval', 'listing_limit', 'staff_limit', 'features', 'free_spotlights'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'features' => 'array',
        ];
    }

    public static function default(): ?self
    {
        return static::where('code', config('lotlink.default_plan'))->first();
    }
}
