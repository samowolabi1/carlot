<?php

namespace App\Domain\Deals\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A hold on a car for 24, 48 or 72 hours (TDD M12). The buyer transfers the deposit straight to
 * the seller's bank account (CarYard never holds it) quoting `reference`; the hold starts when the
 * lot confirms the money arrived (`ActivateReservation`). One active reservation per car; the
 * car shows as Reserved. A sale converts it and the deposit counts towards the price.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $vehicle_id
 * @property int $customer_id
 * @property int|null $lead_id
 * @property int|null $offer_id
 * @property int|null $payment_id only for deposits paid online before CarYard stopped taking buyer payments
 * @property string|null $reference
 * @property int $amount
 * @property int $price
 * @property string $currency
 * @property int $hours
 * @property Carbon|null $pay_by
 * @property Carbon|null $buyer_paid_at
 * @property int|null $confirmed_by
 * @property bool $refund_due
 * @property Carbon|null $refunded_at
 * @property ReservationStatus $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property int|null $sales_order_id
 * @property Carbon|null $created_at
 */
class Reservation extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const HOURS = [24, 48, 72];

    /** A request lapses if the seller hasn't confirmed the transfer within this many hours. */
    public const PAY_WITHIN_HOURS = 12;

    protected $fillable = ['lot_id', 'vehicle_id', 'customer_id', 'lead_id', 'offer_id', 'payment_id', 'amount', 'price', 'currency', 'hours', 'status', 'reference', 'pay_by', 'buyer_paid_at', 'confirmed_by', 'refund_due', 'refunded_at', 'activated_at', 'expires_at', 'ended_at', 'end_reason', 'sales_order_id'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'customer_id', 'lead_id', 'offer_id', 'payment_id', 'sales_order_id', 'confirmed_by'];

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation): void {
            $reservation->reference ??= self::newReference();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'amount' => 'integer',
            'price' => 'integer',
            'hours' => 'integer',
            'pay_by' => 'datetime',
            'buyer_paid_at' => 'datetime',
            'refund_due' => 'boolean',
            'refunded_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
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

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withoutGlobalScope('lot');
    }

    public function money(?int $amount = null): string
    {
        return (string) Money::format($amount ?? $this->amount, $this->currency);
    }

    private static function newReference(): string
    {
        do {
            $reference = 'RES-'.Str::upper(Str::random(6));
        } while (self::withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }

    /** The active hold on a car, if any. */
    public static function activeFor(int $vehicleId): ?self
    {
        return self::withoutGlobalScopes()->where('vehicle_id', $vehicleId)->where('status', ReservationStatus::Active)->first();
    }
}
