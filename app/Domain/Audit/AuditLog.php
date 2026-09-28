<?php

namespace App\Domain\Audit;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who changed prices, statuses, staff or payments, and when (TDD: Security).
 *
 * @property int $id
 * @property int|null $user_id
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

    protected $fillable = ['user_id', 'lot_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @param array<string, mixed> $changes */
    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $user = null, ?int $lotId = null): self
    {
        return self::create([
            'user_id' => ($user ?? auth()->user())?->getKey(),
            'lot_id' => $lotId ?? $subject?->getAttribute('lot_id'),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
