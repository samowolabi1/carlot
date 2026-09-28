<?php

namespace App\Domain\Lots\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Lots\Enums\LotRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lot_id
 * @property string $phone_or_email
 * @property LotRole $role
 * @property string $token
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
class LotInvitation extends Model
{
    use BelongsToLot;

    protected $fillable = ['lot_id', 'phone_or_email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => LotRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @param Builder<LotInvitation> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isEmail(): bool
    {
        return str_contains($this->phone_or_email, '@');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Whether this invitation was addressed to the given user. */
    public function isFor(User $user): bool
    {
        return $this->isEmail()
            ? $user->email !== null && strcasecmp($user->email, $this->phone_or_email) === 0
            : $user->phone === $this->phone_or_email;
    }
}
