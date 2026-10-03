<?php

namespace App\Domain\Leads\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One chat message, stored as plain text (never Markdown or HTML; Vue escapes it).
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $sender_id
 * @property string $side
 * @property string $body
 * @property string|null $attachment_path
 * @property Carbon $created_at
 */
class Message extends Model
{
    use StoresUtc;

    public const CUSTOMER = 'customer';

    public const LOT = 'lot';

    public const SYSTEM = 'system';

    protected $fillable = ['conversation_id', 'sender_id', 'side', 'body', 'attachment_path'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachmentUrl(): ?string
    {
        return $this->attachment_path ? Storage::disk(config('lotlink.media_disk'))->url($this->attachment_path) : null;
    }

    /** @return array<string, mixed> the shape both chat screens and broadcasts use */
    public function present(string $timezone): array
    {
        return [
            'id' => $this->id,
            'side' => $this->side,
            'body' => $this->body,
            'image' => $this->attachmentUrl(),
            'sender' => $this->side === self::LOT ? ($this->sender?->name ? explode(' ', $this->sender->name)[0] : 'The seller') : null,
            'time' => $this->created_at->copy()->setTimezone($timezone)->format('H:i'),
            'day' => $this->created_at->copy()->setTimezone($timezone)->isToday() ? 'Today' : $this->created_at->copy()->setTimezone($timezone)->format('D j M'),
        ];
    }
}
