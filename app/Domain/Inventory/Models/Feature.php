<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\FeatureGroup;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property FeatureGroup $group
 */
class Feature extends Model
{
    protected $fillable = ['name', 'slug', 'group'];

    protected function casts(): array
    {
        return ['group' => FeatureGroup::class];
    }
}
