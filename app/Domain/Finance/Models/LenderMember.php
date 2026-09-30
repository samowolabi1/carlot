<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\LenderRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lender_id
 * @property int $user_id
 * @property LenderRole $role
 */
class LenderMember extends Model
{
    protected $fillable = ['lender_id', 'user_id', 'role'];

    protected $hidden = ['id', 'lender_id', 'user_id'];

    protected function casts(): array
    {
        return ['role' => LenderRole::class];
    }

    /** @return BelongsTo<Lender, $this> */
    public function lender(): BelongsTo
    {
        return $this->belongsTo(Lender::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
