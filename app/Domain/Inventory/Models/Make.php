<?php

namespace App\Domain\Inventory\Models;

use Database\Factories\MakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 */
class Make extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'logo_path'];

    protected static function newFactory(): MakeFactory
    {
        return MakeFactory::new();
    }

    /** @return HasMany<VehicleModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
