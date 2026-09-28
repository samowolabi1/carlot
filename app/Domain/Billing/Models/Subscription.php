<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A lot's plan and where it stands with paying for it (TDD M16). One row per lot; the
 * payments table holds the history.
 *
 * @property int $id
 * @property int $lot_id
 * @property int $plan_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $cancel_at_period_end
 * @property string|null $provider_ref
 * @property string|null $provider_token
 * @property string|null $customer_code
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property int|null $coupon_id
 * @property Carbon|null $trial_reminded_at
 */
class Subscription extends Model
{
    use StoresUtc;

    protected $fillable = [
        'lot_id', 'plan_id', 'status', 'trial_ends_at', 'current_period_end', 'grace_ends_at', 'cancel_at_period_end',
        'provider_ref', 'provider_token', 'customer_code', 'card_brand', 'card_last4', 'coupon_id', 'trial_reminded_at',
    ];

    protected $hidden = ['id', 'lot_id', 'plan_id', 'provider_token', 'coupon_id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancel_at_period_end' => 'datetime',
            'trial_reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isPaidPlan(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }
}
