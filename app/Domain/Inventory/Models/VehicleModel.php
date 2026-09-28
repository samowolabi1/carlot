<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\BodyType;
use Database\Factories\VehicleModelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $make_id
 * @property string $name
 * @property string $slug
 * @property BodyType|null $body_type
 * @property Carbon|null $approved_at
 * @property int|null $created_by
 */
class VehicleModel extends Model
{
    use HasFactory;

    protected $fillable = ['make_id', 'name', 'slug', 'body_type', 'approved_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'body_type' => BodyType::class,
            'approved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): VehicleModelFactory
    {
        return VehicleModelFactory::new();
    }

    /** @return BelongsTo<Make, $this> */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /** @param Builder<VehicleModel> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }
}
