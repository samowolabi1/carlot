<?php

namespace App\Domain\Accounts\Models;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Finance\Models\Budget;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $ulid
 * @property string|null $name
 * @property string|null $phone null when the person signs in with email
 * @property string|null $email
 * @property string|null $password optional: set on the account page to sign in with email/phone and password
 * @property Carbon|null $password_changed_at
 * @property string|null $google_id the linked Google account ("sub"), for "Continue with Google"
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $anonymised_at
 * @property UserRole $role
 * @property Carbon|null $inspector_since
 * @property string|null $inspector_company
 * @property string $locale
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $deleted_at
 * @property array<string, array<string, bool>>|null $notification_preferences
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
        'two_factor_secret',
        'two_factor_recovery_codes',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'notification_preferences' => 'array',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'role' => UserRole::class,
            'inspector_since' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'anonymised_at' => 'datetime',
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

    /** @return BelongsToMany<Lot, $this> */
    public function followedLots(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'lot_followers')->withTimestamps();
    }

    /** @return HasOne<Budget, $this> */
    public function budget(): HasOne
    {
        return $this->hasOne(Budget::class);
    }

    /**
     * Signing in (any way) within 30 days of asking to delete the account cancels the deletion.
     * False when the account is gone for good and can't be signed in to.
     */
    public function reopenForSignIn(): bool
    {
        if (! $this->trashed()) {
            return true;
        }
        if ($this->deletion_requested_at === null || $this->anonymised_at !== null) {
            return false;
        }

        $this->restore();
        $this->deletion_requested_at = null;

        return true;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** A registered independent inspector (TDD M14); set by an admin. */
    public function isInspector(): bool
    {
        return $this->inspector_since !== null;
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
