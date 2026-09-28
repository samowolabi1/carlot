<?php

namespace App\Domain\Lots\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lot_id
 * @property int $user_id
 * @property LotRole $role
 * @property int|null $invited_by
 * @property Carbon|null $accepted_at
 */
class LotMember extends Pivot
{
    protected $table = 'lot_members';

    public $incrementing = true;

    protected $fillable = ['lot_id', 'user_id', 'role', 'invited_by', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'role' => LotRole::class,
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
