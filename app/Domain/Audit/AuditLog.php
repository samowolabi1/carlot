<?php

namespace App\Domain\Audit;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who changed prices, statuses, staff or payments, and when (TDD: Security).
 *
 * @property int $id
 * @property int|null $user_id the account that did it
 * @property int|null $impersonator_id the admin who was using that account through "Log in as", if any
 * @property int|null $lot_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'impersonator_id', 'lot_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class)->withTrashed();
    }

    /** @param array<string, mixed> $changes */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $user = null, ?int $lotId = null): self
    {
        return self::create([
            'user_id' => ($user ?? auth()->user())?->getKey(),
            // During "Log in as", the admin behind it (so support actions are never mistaken for the seller's own).
            // (Queued jobs and commands have no session, so nothing is picked up there.)
            'impersonator_id' => request()->hasSession() ? request()->session()->get(Impersonation::SESSION_KEY) : null,
            'lot_id' => $lotId ?? $subject?->getAttribute('lot_id'),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
