<?php

namespace App\Domain\Leads\Models;

use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The chat on a lead: the buyer on one side, the lot's staff on the other (TDD M11).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lead_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $customer_read_at
 * @property Carbon|null $lot_read_at
 * @property Carbon|null $customer_notified_at
 * @property Carbon|null $lot_notified_at
 */
class Conversation extends Model
{
    use HasUlids, StoresUtc;

    protected $fillable = ['lead_id', 'last_message_at', 'customer_read_at', 'lot_read_at'];

    protected $hidden = ['id', 'lead_id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'customer_read_at' => 'datetime',
            'lot_read_at' => 'datetime',
            'customer_notified_at' => 'datetime',
            'lot_notified_at' => 'datetime',
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

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope('lot');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * Conversations with messages this side hasn't read, in one query (for badges).
     *
     * @param  Builder<Conversation>  $query
     */
    public function scopeUnreadFor(Builder $query, string $side): void
    {
        $readColumn = $side === Message::CUSTOMER ? 'customer_read_at' : 'lot_read_at';
        $from = $side === Message::CUSTOMER ? Message::LOT : Message::CUSTOMER;

        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('messages')
            ->whereColumn('messages.conversation_id', 'conversations.id')
            ->where('messages.side', $from)
            ->where(fn ($q) => $q->whereNull("conversations.{$readColumn}")->orWhereColumn('messages.created_at', '>', "conversations.{$readColumn}")));
    }

    /** Messages from the other side that this side hasn't read. */
    public function unreadFor(string $side): int
    {
        $readAt = $side === Message::CUSTOMER ? $this->customer_read_at : $this->lot_read_at;

        return $this->messages()
            ->where('side', $side === Message::CUSTOMER ? Message::LOT : Message::CUSTOMER)
            ->when($readAt, fn ($q) => $q->where('created_at', '>', $readAt))
            ->count();
    }
}
