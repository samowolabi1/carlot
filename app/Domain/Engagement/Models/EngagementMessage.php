<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One engagement message to one person (from a broadcast or an automated rule). Its link goes
 * through `/e/{ulid}`, which records the click. Also the cooldown record for rules.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $broadcast_id
 * @property string|null $rule
 * @property int $user_id
 * @property int|null $lot_id
 * @property string|null $url where the link goes
 * @property Carbon $sent_at
 * @property Carbon|null $clicked_at
 */
class EngagementMessage extends Model
{
    use HasUlids;

    protected $fillable = ['broadcast_id', 'rule', 'user_id', 'lot_id', 'url', 'sent_at', 'clicked_at'];

    protected $hidden = ['id', 'user_id', 'lot_id', 'broadcast_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'clicked_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** The tracked link to put in the message. */
    public function link(): string
    {
        return route('engagement.click', $this);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class)->withTrashed();
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }
}
