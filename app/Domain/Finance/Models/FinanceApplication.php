<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A buyer's car loan application to one lender (TDD M10). What the buyer agreed to share is encrypted;
 * the lender sees it, the lot never does. LotLink doesn't lend: the lender decides, in the lender portal
 * or through its own API. Status changes only through UpdateFinanceApplication.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $vehicle_id
 * @property int|null $lot_id
 * @property int|null $lender_id
 * @property int|null $assigned_to
 * @property string $partner lender slug when sent
 * @property int $amount
 * @property int $deposit
 * @property int $tenor_months
 * @property string $currency
 * @property array<string, mixed> $applicant
 * @property Carbon $consented_at
 * @property FinanceStatus $status
 * @property string|null $external_ref
 * @property string|null $partner_message
 * @property string|null $next_steps what the buyer does at the lender to continue (after a pre-approval or approval)
 * @property int|null $approved_amount
 * @property int|null $offer_rate_bp
 * @property int|null $offer_tenor_months
 * @property int|null $disbursed_amount
 * @property string|null $disbursed_reference
 * @property Carbon|null $disbursed_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $buyer_read_at
 * @property Carbon|null $lender_read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FinanceApplication extends Model
{
    use HasUlids, StoresUtc;

    public const EMPLOYMENT = ['salaried' => 'Salaried', 'self_employed' => 'Self-employed', 'business_owner' => 'Business owner', 'other' => 'Other'];

    protected $fillable = ['user_id', 'vehicle_id', 'lot_id', 'partner', 'amount', 'deposit', 'tenor_months', 'currency', 'applicant', 'consented_at', 'status', 'external_ref', 'partner_message', 'next_steps', 'approved_amount',
        'lender_id', 'assigned_to', 'offer_rate_bp', 'offer_tenor_months', 'disbursed_amount', 'disbursed_reference', 'disbursed_at', 'decided_at', 'buyer_read_at', 'lender_read_at'];

    protected $hidden = ['id', 'user_id', 'vehicle_id', 'lot_id', 'lender_id', 'assigned_to', 'applicant'];

    protected function casts(): array
    {
        return [
            'applicant' => 'encrypted:array',
            'consented_at' => 'datetime',
            'amount' => 'integer',
            'deposit' => 'integer',
            'approved_amount' => 'integer',
            'tenor_months' => 'integer',
            'status' => FinanceStatus::class,
            'offer_rate_bp' => 'integer',
            'offer_tenor_months' => 'integer',
            'disbursed_amount' => 'integer',
            'disbursed_at' => 'datetime',
            'decided_at' => 'datetime',
            'buyer_read_at' => 'datetime',
            'lender_read_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class)->withTrashed();
    }

    /** @return BelongsTo<Lender, $this> */
    public function lender(): BelongsTo
    {
        return $this->belongsTo(Lender::class);
    }

    /** @return BelongsTo<User, $this> the lender's officer handling it */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<FinanceMessage, $this> oldest first */
    public function messages(): HasMany
    {
        return $this->hasMany(FinanceMessage::class)->orderBy('id');
    }

    public function statusLabel(): string
    {
        return $this->status->label();
    }

    public function lenderName(): string
    {
        return $this->lender->name ?? 'the lender';
    }

    /** Unread for this side: the other side wrote (or the status moved) after this side last looked. */
    public function unreadFor(string $side): bool
    {
        $read = $side === FinanceMessage::BUYER ? $this->buyer_read_at : $this->lender_read_at;
        $last = $this->messages()->where('side', '!=', $side)->max('created_at');

        return $last !== null && ($read === null || $read->lt($last));
    }

    public function money(?int $amount = null): string
    {
        return (string) Money::format($amount ?? $this->amount, $this->currency);
    }
}
