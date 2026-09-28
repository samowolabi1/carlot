<?php

namespace App\Domain\Deals\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer's offer on a car (TDD M12). The lot accepts, declines or counters; the buyer can
 * accept or decline a counter, or make a new offer. Open offers expire after 48 hours.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $lead_id
 * @property int $vehicle_id
 * @property int $customer_id
 * @property int $amount
 * @property string $currency
 * @property string|null $message
 * @property OfferStatus $status
 * @property int|null $counter_amount
 * @property string|null $counter_message
 * @property int|null $responded_by
 * @property Carbon|null $responded_at
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
class Offer extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const HOURS = 48;

    protected $fillable = ['lot_id', 'lead_id', 'vehicle_id', 'customer_id', 'amount', 'currency', 'message', 'status', 'counter_amount', 'counter_message', 'responded_by', 'responded_at', 'expires_at', 'closed_at'];

    protected $hidden = ['id', 'lot_id', 'lead_id', 'vehicle_id', 'customer_id', 'responded_by'];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'amount' => 'integer',
            'counter_amount' => 'integer',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope('lot');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, OfferStatus::open(), true);
    }

    /** The price both sides agreed: the counter if the buyer took it, otherwise the offer. */
    public function agreedAmount(): ?int
    {
        if ($this->status !== OfferStatus::Accepted) {
            return null;
        }

        return $this->counter_amount ?? $this->amount;
    }

    public function money(?int $amount = null): string
    {
        return (string) Money::format($amount ?? $this->amount, $this->currency);
    }

    /** "−5.6%" against the asking price. */
    public static function discount(int $amount, ?int $price): ?string
    {
        if (! $price) {
            return null;
        }

        $pct = round(($price - $amount) / $price * 100, 1);

        return $pct > 0 ? '−'.rtrim(rtrim(number_format($pct, 1), '0'), '.').'%' : null;
    }
}
