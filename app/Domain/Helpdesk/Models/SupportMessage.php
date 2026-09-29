<?php

namespace App\Domain\Helpdesk\Models;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * One message on a support ticket. Attachments stay on the private `local` disk and open
 * through short-lived signed links (`support.attachment`) for the lot's staff and admins.
 *
 * @property int $id
 * @property string $ulid
 * @property int $support_ticket_id
 * @property int|null $user_id
 * @property bool $from_admin
 * @property bool $internal
 * @property string $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property Carbon $created_at
 */
class SupportMessage extends Model
{
    use HasUlids;

    public const DISK = 'local';

    protected $fillable = ['support_ticket_id', 'user_id', 'from_admin', 'internal', 'body', 'attachment_path', 'attachment_name'];

    protected $hidden = ['id', 'support_ticket_id', 'user_id', 'attachment_path'];

    protected function casts(): array
    {
        return ['from_admin' => 'boolean', 'internal' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachmentUrl(): ?string
    {
        return $this->attachment_path ? URL::temporarySignedRoute('support.attachment', now()->addMinutes(30), ['message' => $this->ulid]) : null;
    }

    /** Admins show as "LotLink Support" to lots, so staff names stay internal. */
    public function authorLabel(): string
    {
        return $this->from_admin ? 'LotLink Support' : ($this->author->name ?? 'Your team');
    }
}
