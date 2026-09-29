<?php

namespace App\Domain\Helpdesk\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Helpdesk\Enums\TicketCategory;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A lot's support request to LotLink. Replies go through `ReplyToTicket`, status changes
 * through `ChangeTicketStatus`; the lot sees everything except internal admin notes.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference
 * @property int $lot_id
 * @property int|null $user_id
 * @property int|null $vehicle_id
 * @property string $subject
 * @property TicketCategory $category
 * @property TicketPriority $priority
 * @property TicketStatus $status
 * @property int|null $assigned_to
 * @property Carbon|null $last_message_at
 * @property Carbon|null $lot_read_at
 * @property Carbon|null $admin_read_at
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
class SupportTicket extends Model
{
    use BelongsToLot, HasUlids;

    protected $fillable = ['lot_id', 'user_id', 'vehicle_id', 'subject', 'category', 'priority', 'status', 'assigned_to', 'last_message_at', 'lot_read_at', 'admin_read_at', 'resolved_at'];

    protected $hidden = ['id', 'lot_id', 'user_id', 'vehicle_id', 'assigned_to'];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket): void {
            $ticket->reference ??= self::newReference();
        });
    }

    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'last_message_at' => 'datetime',
            'lot_read_at' => 'datetime',
            'admin_read_at' => 'datetime',
            'resolved_at' => 'datetime',
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
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<SupportMessage, $this> what the lot may see (no internal notes) */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('internal', false);
    }

    /** @param Builder<SupportTicket> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), TicketStatus::active());
    }

    /**
     * Tickets with a LotLink reply the lot hasn't opened (`ReplyToTicket` clears `lot_read_at`).
     *
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeUnreadByLot(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('lot_read_at'))->where($query->qualifyColumn('status'), '!=', TicketStatus::Open);
    }

    public function isUnreadByLot(): bool
    {
        return $this->lot_read_at === null && $this->status !== TicketStatus::Open;
    }

    /** A lot's message nobody at LotLink has opened (`ReplyToTicket` clears `admin_read_at`). */
    public function isUnreadByAdmin(): bool
    {
        return $this->admin_read_at === null && $this->status === TicketStatus::Open;
    }

    private static function newReference(): string
    {
        do {
            $reference = 'T-'.Str::upper(Str::random(6));
        } while (self::withoutGlobalScopes()->where('reference', $reference)->exists());

        return $reference;
    }
}
