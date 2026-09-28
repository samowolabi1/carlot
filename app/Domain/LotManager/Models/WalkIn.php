<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\Interest;
use App\Domain\LotManager\Enums\NextStep;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $lot_customer_id
 * @property int|null $staff_id
 * @property Carbon $visited_at
 * @property list<int>|null $vehicles_viewed
 * @property Interest $interest
 * @property NextStep $next_step
 * @property Carbon|null $follow_up_at
 * @property string|null $notes
 * @property string|null $client_uuid
 */
class WalkIn extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    protected $fillable = ['lot_id', 'lot_customer_id', 'staff_id', 'visited_at', 'vehicles_viewed', 'interest', 'next_step', 'follow_up_at', 'notes', 'client_uuid'];

    protected $hidden = ['id', 'lot_id', 'lot_customer_id', 'staff_id'];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'vehicles_viewed' => 'array',
            'interest' => Interest::class,
            'next_step' => NextStep::class,
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<LotCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(LotCustomer::class, 'lot_customer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
