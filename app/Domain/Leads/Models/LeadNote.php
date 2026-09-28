<?php

namespace App\Domain\Leads\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $created_at
 */
class LeadNote extends Model
{
    use StoresUtc;

    protected $fillable = ['lead_id', 'user_id', 'body'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
