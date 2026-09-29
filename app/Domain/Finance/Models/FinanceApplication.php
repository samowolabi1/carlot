<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer's pre-qualification request handed to a finance partner (TDD M10). What the buyer agreed
 * to share is encrypted; the lot never sees it. LotLink doesn't lend: the partner decides.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property int|null $vehicle_id
 * @property int|null $lot_id
 * @property string $partner
 * @property int $amount
 * @property int $deposit
 * @property int $tenor_months
 * @property string $currency
 * @property array<string, mixed> $applicant
 * @property Carbon $consented_at
 * @property string $status submitted, received, pre_approved, declined, failed
 * @property string|null $external_ref
 * @property string|null $partner_message
 * @property int|null $approved_amount
 * @property Carbon $created_at
 */
class FinanceApplication extends Model
{
    use HasUlids, StoresUtc;

    public const STATUSES = [
        'submitted' => 'Sent',
        'received' => 'Being reviewed',
        'pre_approved' => 'Pre-approved',
        'declined' => 'Not approved',
        'failed' => "Couldn't send",
    ];

    public const EMPLOYMENT = ['salaried' => 'Salaried', 'self_employed' => 'Self-employed', 'business_owner' => 'Business owner', 'other' => 'Other'];

    protected $fillable = ['user_id', 'vehicle_id', 'lot_id', 'partner', 'amount', 'deposit', 'tenor_months', 'currency', 'applicant', 'consented_at', 'status', 'external_ref', 'partner_message', 'approved_amount'];

    protected $hidden = ['id', 'user_id', 'vehicle_id', 'lot_id', 'applicant'];

    protected function casts(): array
    {
        return [
            'applicant' => 'encrypted:array',
            'consented_at' => 'datetime',
            'amount' => 'integer',
            'deposit' => 'integer',
            'approved_amount' => 'integer',
            'tenor_months' => 'integer',
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function money(?int $amount = null): string
    {
        return (string) Money::format($amount ?? $this->amount, $this->currency);
    }
}
