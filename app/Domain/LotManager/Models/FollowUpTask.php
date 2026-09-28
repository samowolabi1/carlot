<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\FollowUpType;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $lot_customer_id
 * @property int|null $walk_in_id
 * @property int|null $assigned_to
 * @property FollowUpType $type
 * @property Carbon $due_at
 * @property string|null $note
 * @property Carbon|null $reminded_at
 * @property Carbon|null $done_at
 */
class FollowUpTask extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    protected $fillable = ['lot_id', 'lot_customer_id', 'walk_in_id', 'assigned_to', 'type', 'due_at', 'note'];

    protected $hidden = ['id', 'lot_id', 'lot_customer_id', 'walk_in_id', 'assigned_to'];

    protected function casts(): array
    {
        return [
            'type' => FollowUpType::class,
            'due_at' => 'datetime',
            'reminded_at' => 'datetime',
            'done_at' => 'datetime',
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

    /** @return BelongsTo<LotCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LotCustomer::class, 'lot_customer_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @param Builder<FollowUpTask> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at');
    }
}
