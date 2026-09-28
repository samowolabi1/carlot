<?php

namespace App\Domain\Lots\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lot that signed up with another lot's referral code (TDD M19: growth hooks). When it
 * first pays for a plan, RewardReferral gives the referrer a month.
 *
 * @property int $id
 * @property int $referrer_lot_id
 * @property int|null $referred_lot_id
 * @property string $status signed_up, subscribed, rewarded
 * @property int $reward_months
 * @property Carbon|null $rewarded_at
 * @property Carbon|null $created_at
 */
class LotReferral extends Model
{
    protected $fillable = ['referrer_lot_id', 'referred_lot_id', 'status', 'reward_months', 'rewarded_at'];

    protected $hidden = ['id', 'referrer_lot_id', 'referred_lot_id'];

    protected function casts(): array
    {
        return ['reward_months' => 'integer', 'rewarded_at' => 'datetime'];
    }

    /** @return BelongsTo<Lot, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'referrer_lot_id')->withTrashed();
    }

    /** @return BelongsTo<Lot, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'referred_lot_id')->withTrashed();
    }
}
