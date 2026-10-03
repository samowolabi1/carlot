<?php

namespace App\Domain\Trust\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Name;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One review per completed appointment (TDD M14). Editable by the buyer for 14 days; the seller
 * may reply once. Reported reviews are hidden until an admin looks at them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $appointment_id
 * @property int $user_id
 * @property int $rating
 * @property list<string>|null $tags
 * @property string|null $body
 * @property string|null $reply
 * @property int|null $replied_by
 * @property Carbon|null $reply_at
 * @property ReviewStatus $status
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 */
class Review extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const EDIT_DAYS = 14;

    public const MIN_FOR_RATING = 3;

    /** "What went well?" chips from design 17. */
    public const TAGS = [
        'as_described' => 'Car as described',
        'friendly' => 'Friendly staff',
        'honest_price' => 'Honest pricing',
        'on_time' => 'On time',
        'quick_replies' => 'Quick replies',
    ];

    protected $fillable = ['lot_id', 'appointment_id', 'user_id', 'rating', 'tags', 'body', 'reply', 'replied_by', 'reply_at', 'status', 'edited_at'];

    protected $hidden = ['id', 'lot_id', 'appointment_id', 'user_id', 'replied_by'];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'tags' => 'array',
            'status' => ReviewStatus::class,
            'reply_at' => 'datetime',
            'edited_at' => 'datetime',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withoutGlobalScopes();
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /** @param Builder<Review> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ReviewStatus::Visible);
    }

    public function editableUntil(): Carbon
    {
        return $this->created_at->copy()->addDays(self::EDIT_DAYS);
    }

    public function canBeEdited(): bool
    {
        return $this->editableUntil()->isFuture();
    }

    /** "Tunde A." as design 17 promises: first name and initial only. */
    public function authorName(): string
    {
        return Name::short($this->author?->name, 'A buyer');
    }

    /** @return list<string> */
    public function tagLabels(): array
    {
        return collect($this->tags ?? [])->map(fn (string $t) => self::TAGS[$t] ?? null)->filter()->values()->all();
    }
}
