<?php

namespace App\Domain\Leads\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A buyer's interest in a lot (usually in one car), from any source: chat, WhatsApp tap,
 * booking, call and, later, offers (TDD M11). Linked to the lot's customer book.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $vehicle_id
 * @property int|null $customer_id
 * @property int|null $lot_customer_id
 * @property LeadSource $source
 * @property LeadStage $stage
 * @property int|null $assigned_to
 * @property string|null $lost_reason
 * @property Carbon|null $next_follow_up_at
 * @property Carbon|null $follow_up_reminded_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
class Lead extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    protected $fillable = ['lot_id', 'vehicle_id', 'customer_id', 'lot_customer_id', 'source', 'stage', 'assigned_to', 'lost_reason', 'next_follow_up_at', 'last_activity_at', 'closed_at'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'customer_id', 'lot_customer_id', 'assigned_to'];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'stage' => LeadStage::class,
            'next_follow_up_at' => 'datetime',
            'follow_up_reminded_at' => 'datetime',
            'first_response_at' => 'datetime',
            'last_activity_at' => 'datetime',
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

    /** @return BelongsTo<LotCustomer, $this> */
    public function lotCustomer(): BelongsTo
    {
        return $this->belongsTo(LotCustomer::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasOne<Conversation, $this> */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /** @return HasMany<LeadNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest();
    }

    public function isOpen(): bool
    {
        return ! $this->stage->isClosed();
    }
}
