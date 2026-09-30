<?php

namespace App\Domain\Legal\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\Lender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proof that someone accepted a version of a legal document (and, for the Lender Terms, for which lender): kept as
 * evidence of the electronic agreement, with when and from where.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $lender_id
 * @property string $document terms | privacy | lender-terms
 * @property string $version
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $accepted_at
 */
class LegalAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'lender_id', 'document', 'version', 'ip', 'user_agent', 'accepted_at'];

    protected $hidden = ['id', 'user_id', 'lender_id', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lender, $this> */
    public function lender(): BelongsTo
    {
        return $this->belongsTo(Lender::class);
    }
}
