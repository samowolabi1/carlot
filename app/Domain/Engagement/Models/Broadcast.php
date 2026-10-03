<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An announcement, tip or promo from CarYard to sellers (and optionally managers), sent to a
 * chosen group of lots (`BroadcastAudience`) through in-app, push, email and optionally WhatsApp.
 *
 * @property int $id
 * @property string $ulid
 * @property string $title
 * @property string $body
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property array{states?: list<string>, plans?: list<int>, status?: string|null, verified?: string|null, activity?: string|null, managers?: bool} $audience
 * @property list<string> $channels mail, push, whatsapp (in-app is always on)
 * @property string $status draft, scheduled, sending, sent, cancelled
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $sent_at
 * @property int $recipients_count
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class Broadcast extends Model
{
    use HasUlids;

    protected $table = 'engagement_broadcasts';

    protected $fillable = ['title', 'body', 'cta_label', 'cta_url', 'audience', 'channels', 'status', 'scheduled_at', 'sent_at', 'recipients_count', 'created_by'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'channels' => 'array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'recipients_count' => 'integer'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<EngagementMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(EngagementMessage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
