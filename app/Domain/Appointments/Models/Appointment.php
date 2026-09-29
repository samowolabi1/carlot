<?php

namespace App\Domain\Appointments\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Models\Review;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $vehicle_id
 * @property int $customer_id
 * @property int|null $staff_id
 * @property AppointmentType $type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property AppointmentStatus $status
 * @property string|null $notes
 * @property bool $whatsapp_reminders
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $review_invited_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $reminded_24h_at
 * @property Carbon|null $reminded_2h_at
 * @property Carbon|null $escalated_at
 * @property int|null $deposit_payment_id
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use BelongsToLot, HasFactory, HasUlids, StoresUtc;

    protected $fillable = [
        'lot_id', 'vehicle_id', 'customer_id', 'staff_id', 'type', 'starts_at', 'ends_at', 'status', 'notes',
        'whatsapp_reminders', 'confirmed_at',
    ];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'customer_id', 'staff_id'];

    protected function casts(): array
    {
        return [
            'type' => AppointmentType::class,
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'whatsapp_reminders' => 'boolean',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'review_invited_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminded_24h_at' => 'datetime',
            'reminded_2h_at' => 'datetime',
            'escalated_at' => 'datetime',
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

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @param Builder<Appointment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::active());
    }

    /**
     * Bookings that take a place in a slot: active ones, plus test drives whose deposit is
     * being paid.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeHoldingSlot(Builder $query): void
    {
        $query->whereIn('status', [...AppointmentStatus::active(), AppointmentStatus::AwaitingDeposit]);
    }

    /** @return HasOne<Review, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class)->withoutGlobalScopes();
    }

    /** A buyer can review a visit that happened (TDD M14: only after a completed appointment). */
    public function canBeReviewed(): bool
    {
        return $this->status === AppointmentStatus::Completed;
    }

    /** @return BelongsTo<Payment, $this> */
    public function depositPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'deposit_payment_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, AppointmentStatus::active(), true);
    }

    public function isUpcoming(): bool
    {
        return $this->isActive() && $this->starts_at->isFuture();
    }

    /** "Tue 29 Sep, 10:30" in the lot's timezone. */
    public function whenLabel(string $timezone): string
    {
        return $this->starts_at->copy()->setTimezone($timezone)->format('D j M, H:i');
    }
}
