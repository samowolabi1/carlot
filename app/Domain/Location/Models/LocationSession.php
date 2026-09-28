<?php

namespace App\Domain\Location\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Live location for one appointment (TDD M8): one side shares for 15–120 minutes and only
 * the other side of that booking can follow. Only the latest point is kept, and it is
 * cleared when the session ends.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $appointment_id
 * @property int $sharer_id
 * @property string $sharer_side customer or lot
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property float|null $last_latitude
 * @property float|null $last_longitude
 * @property int|null $accuracy_m
 * @property Carbon|null $last_seen_at
 */
class LocationSession extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const MINUTES = [15, 30, 60, 120];

    public const CUSTOMER = 'customer';

    public const LOT = 'lot';

    protected $fillable = ['lot_id', 'appointment_id', 'sharer_id', 'sharer_side', 'started_at', 'expires_at'];

    protected $hidden = ['id', 'lot_id', 'appointment_id', 'sharer_id', 'last_latitude', 'last_longitude'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
            'accuracy_m' => 'integer',
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

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withoutGlobalScope('lot');
    }

    /** @return BelongsTo<User, $this> */
    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sharer_id');
    }

    /** @param Builder<LocationSession> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** @return array{lat: float, lng: float, accuracy: ?int, at: ?string}|null */
    public function point(): ?array
    {
        if ($this->last_latitude === null || $this->last_longitude === null || ! $this->isLive()) {
            return null;
        }

        return ['lat' => $this->last_latitude, 'lng' => $this->last_longitude, 'accuracy' => $this->accuracy_m, 'at' => $this->last_seen_at?->toIso8601String()];
    }
}
