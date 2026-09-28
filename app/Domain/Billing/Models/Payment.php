<?php

namespace App\Domain\Billing\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money paid through Paystack: a lot paying LotLink for plans, renewals and spotlights (TDD M16),
 * and buyers' reservation and test-drive deposits (M12, user_id is the buyer). Not to be
 * confused with Lot Manager's order_payments (payments a lot records itself).
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $payable_type
 * @property int|null $payable_id
 * @property int|null $user_id
 * @property int|null $lot_id
 * @property PaymentPurpose $purpose
 * @property string $description
 * @property int $amount
 * @property string $currency
 * @property string $provider
 * @property string $reference
 * @property PaymentStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    use HasUlids, StoresUtc;

    protected $fillable = ['payable_type', 'payable_id', 'user_id', 'lot_id', 'purpose', 'description', 'amount', 'currency', 'provider', 'reference', 'status', 'paid_at', 'meta'];

    protected $hidden = ['id', 'payable_type', 'payable_id', 'user_id', 'lot_id'];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
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

    /** @return MorphTo<Model, $this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function money(): string
    {
        return (string) Money::format($this->amount, $this->currency);
    }

    /** "LL-2026-000042": LotLink's invoice number for this payment. */
    public function invoiceNumber(): string
    {
        return sprintf('LL-%d-%06d', ($this->paid_at ?? $this->created_at ?? now())->year, $this->id);
    }

    /** A payment reference we make: unguessable and unique. */
    public static function newReference(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }
}
