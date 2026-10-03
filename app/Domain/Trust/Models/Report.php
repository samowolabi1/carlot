<?php

namespace App\Domain\Trust\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Enums\ReportReason;
use App\Domain\Trust\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A user's report on a listing, lot, review or chat message (TDD M14). Read only in the admin
 * panel, so it is not lot-scoped; `lot_id` is the seller the content belongs to.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int|null $lot_id
 * @property int $user_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property ReportStatus $status
 * @property int|null $handled_by
 * @property Carbon|null $handled_at
 * @property Carbon $created_at
 * @property-read Vehicle|Lot|Review|Message|null $reportable
 */
class Report extends Model
{
    use HasUlids, StoresUtc;

    /** Report kinds in URLs and forms => morph class. */
    public const KINDS = [
        'vehicle' => Vehicle::class,
        'lot' => Lot::class,
        'review' => Review::class,
        'message' => Message::class,
    ];

    /** Open reports on one listing that take it off the marketplace until an admin looks. */
    public const HOLD_AFTER = 3;

    protected $fillable = ['reportable_type', 'reportable_id', 'lot_id', 'user_id', 'reason', 'details', 'status', 'handled_by', 'handled_at'];

    protected $hidden = ['id', 'reportable_id', 'lot_id', 'user_id', 'handled_by'];

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function kind(): string
    {
        return (string) array_search($this->reportable_type, self::KINDS, true);
    }
}
