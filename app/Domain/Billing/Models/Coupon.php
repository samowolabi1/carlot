<?php

namespace App\Domain\Billing\Models;

use App\Domain\Lots\Models\Plan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A code that starts a longer free trial on a plan, e.g. the launch offer of 3 months on
 * Starter (TDD M16).
 *
 * @property int $id
 * @property string $code
 * @property int $plan_id
 * @property int $trial_days
 * @property int|null $max_redemptions
 * @property int $redeemed
 * @property Carbon|null $expires_at
 * @property bool $active paused codes can't be redeemed
 * @property string|null $note what it's for (admins only)
 */
class Coupon extends Model
{
    protected $fillable = ['code', 'plan_id', 'trial_days', 'max_redemptions', 'redeemed', 'expires_at', 'active', 'note'];

    /** Letters, digits and dashes, so codes are easy to read out and type. */
    public const PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,31}$/';

    protected function casts(): array
    {
        return ['trial_days' => 'integer', 'max_redemptions' => 'integer', 'redeemed' => 'integer', 'expires_at' => 'datetime', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Coupon $coupon) => $coupon->code = strtoupper(trim($coupon->code)));
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Subscription, $this> the sellers that redeemed it */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    /** active, paused, expired or used_up */
    public function state(): string
    {
        return match (true) {
            ! $this->active => 'paused',
            $this->expires_at !== null && ! $this->expires_at->isFuture() => 'expired',
            $this->max_redemptions !== null && $this->redeemed >= $this->max_redemptions => 'used_up',
            default => 'active',
        };
    }

    public function isUsable(): bool
    {
        return $this->active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_redemptions === null || $this->redeemed < $this->max_redemptions);
    }
}
