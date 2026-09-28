<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\DutyStatus;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\MediaStatus;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Support\Money;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $make_id
 * @property int|null $vehicle_model_id
 * @property string|null $slug
 * @property int|null $year
 * @property string|null $trim
 * @property BodyType|null $body_type
 * @property int|null $mileage_km
 * @property int|null $price
 * @property string $currency
 * @property bool $negotiable
 * @property VehicleCondition|null $condition
 * @property Transmission|null $transmission
 * @property FuelType|null $fuel
 * @property int|null $engine_cc
 * @property Drivetrain|null $drivetrain
 * @property string|null $colour
 * @property string|null $interior_colour
 * @property string|null $vin
 * @property DutyStatus|null $duty_status
 * @property bool $registered
 * @property string|null $description
 * @property VehicleStatus $status
 * @property Carbon|null $spotlight_until
 * @property Carbon|null $listed_at
 * @property Carbon|null $sold_at
 * @property Carbon|null $price_changed_at
 * @property string|null $share_card_hash
 * @property int|null $created_by
 * @property-read int|null $ready_media_count
 * @property-read Pivot $pivot
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use BelongsToLot, HasFactory, HasUlids, Searchable, SoftDeletes;

    public const MAX_PHOTOS = 20;

    public const NEW_ARRIVAL_DAYS = 7;

    public const AGEING_DAYS = 45;

    protected $fillable = [
        'lot_id', 'make_id', 'vehicle_model_id', 'year', 'trim', 'body_type', 'mileage_km', 'price', 'currency',
        'negotiable', 'condition', 'transmission', 'fuel', 'engine_cc', 'drivetrain', 'colour', 'interior_colour',
        'vin', 'duty_status', 'registered', 'description', 'created_by',
    ];

    protected $hidden = ['id', 'lot_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mileage_km' => 'integer',
            'price' => 'integer',
            'engine_cc' => 'integer',
            'negotiable' => 'boolean',
            'registered' => 'boolean',
            'body_type' => BodyType::class,
            'condition' => VehicleCondition::class,
            'transmission' => Transmission::class,
            'fuel' => FuelType::class,
            'drivetrain' => Drivetrain::class,
            'duty_status' => DutyStatus::class,
            'status' => VehicleStatus::class,
            'spotlight_until' => 'datetime',
            'listed_at' => 'datetime',
            'sold_at' => 'datetime',
            'price_changed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected static function newFactory(): VehicleFactory
    {
        return VehicleFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle): void {
            if ($vehicle->isDirty(['year', 'make_id', 'vehicle_model_id', 'trim'])) {
                // Drop relations cached for the old ids, then build the slug from fresh names.
                $vehicle->unsetRelation('make')->unsetRelation('model');

                $vehicle->slug = Str::slug(implode(' ', array_filter([
                    $vehicle->year,
                    $vehicle->make_id ? Make::whereKey($vehicle->make_id)->value('name') : null,
                    $vehicle->vehicle_model_id ? VehicleModel::whereKey($vehicle->vehicle_model_id)->value('name') : null,
                    $vehicle->trim,
                ]))) ?: null;
            }
        });
    }

    /** @return BelongsTo<Make, $this> */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<VehicleMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(VehicleMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasOne<VehicleMedia, $this> */
    public function cover(): HasOne
    {
        return $this->hasOne(VehicleMedia::class)->where('is_cover', true)->where('status', MediaStatus::Ready);
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'vehicle_features');
    }

    /** @return HasMany<VehiclePriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(VehiclePriceHistory::class);
    }

    /** @param Builder<Vehicle> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', VehicleStatus::live());
    }

    /**
     * What buyers can see: available and reserved cars at approved lots.
     *
     * @param  Builder<Vehicle>  $query
     */
    public function scopeMarketplace(Builder $query): void
    {
        $query->withoutGlobalScope('lot')
            ->whereIn($query->qualifyColumn('status'), VehicleStatus::live())
            ->whereHas('lot', fn (Builder $q) => $q->where('status', LotStatus::Active));
    }

    public function isOnMarketplace(): bool
    {
        return in_array($this->status, VehicleStatus::live(), true)
            && ! $this->trashed()
            && $this->lot()->where('status', LotStatus::Active)->exists();
    }

    /** "/car/01j9…-2018-toyota-camry-se" */
    public function publicPath(): string
    {
        return '/car/'.$this->ulid.($this->slug ? '-'.$this->slug : '');
    }

    /* Search index (Scout + Meilisearch). Only marketplace cars are indexed. */

    public function searchableAs(): string
    {
        return config('scout.prefix').'vehicles';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isOnMarketplace();
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['make', 'model', 'lot']);

        return [
            'id' => $this->id,
            'ulid' => $this->ulid,
            'title' => $this->title(),
            'make' => $this->make?->name,
            'model' => $this->model?->name,
            'trim' => $this->trim,
            'description' => $this->description,
            'lot_name' => $this->lot->name,
            'lot_id' => $this->lot_id,
            'city' => $this->lot->city,
            'make_id' => $this->make_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'body_type' => $this->body_type?->value,
            'condition' => $this->condition?->value,
            'transmission' => $this->transmission?->value,
            'fuel' => $this->fuel?->value,
            'colour' => $this->colour ? Str::lower($this->colour) : null,
            'year' => $this->year,
            'price' => $this->price,
            'mileage_km' => $this->mileage_km,
            'status' => $this->status->value,
            'listed_at' => $this->listed_at?->getTimestamp(),
            'spotlight_until' => $this->spotlight_until?->getTimestamp() ?? 0,
            '_geo' => $this->lot->hasLocation() ? ['lat' => $this->lot->latitude, 'lng' => $this->lot->longitude] : null,
        ];
    }

    /** @param Builder<Vehicle> $query */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->withoutGlobalScope('lot')->with(['make', 'model', 'lot']);
    }

    /** "2018 Toyota Camry SE". Needs make and model loaded to avoid extra queries in lists. */
    public function title(): string
    {
        return trim(implode(' ', array_filter([
            $this->year,
            $this->make?->name,
            $this->model?->name,
            $this->trim,
        ])));
    }

    public function isNewArrival(): bool
    {
        return $this->listed_at !== null && $this->listed_at->gt(now()->subDays(self::NEW_ARRIVAL_DAYS));
    }

    public function daysListed(): ?int
    {
        return $this->listed_at ? (int) $this->listed_at->diffInDays($this->sold_at ?? now()) : null;
    }

    public function isAgeing(): bool
    {
        return $this->status === VehicleStatus::Available && ($this->daysListed() ?? 0) >= self::AGEING_DAYS;
    }

    public function formattedPrice(): ?string
    {
        return Money::format($this->price, $this->currency);
    }

    public function vinTail(): ?string
    {
        return $this->vin ? substr($this->vin, -4) : null;
    }
}
