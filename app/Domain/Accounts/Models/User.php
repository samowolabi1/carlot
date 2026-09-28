<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotMember;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $ulid
 * @property string|null $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $password
 * @property UserRole $role
 * @property string $locale
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'role',
        'phone_verified_at',
        'locale',
    ];

    protected $hidden = [
        'id',
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return BelongsToMany<Lot, $this, LotMember> */
    public function lots(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'lot_members')
            ->using(LotMember::class)
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Vehicle, $this> */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'favourites')->withPivot('saved_price')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function roleIn(Lot $lot): ?LotRole
    {
        return LotMember::query()
            ->where('lot_id', $lot->getKey())
            ->where('user_id', $this->getKey())
            ->first()
            ?->role;
    }

    public function hasLotRole(Lot $lot, LotRole ...$roles): bool
    {
        $role = $this->roleIn($lot);

        return $role !== null && ($roles === [] || in_array($role, $roles, true));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function getFilamentName(): string
    {
        return $this->name ?? $this->phone;
    }
}
