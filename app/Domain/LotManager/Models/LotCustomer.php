<?php

namespace App\Domain\LotManager\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * One person in a lot's customer book, matched by phone across walk-ins, calls,
 * WhatsApp and online leads. Belongs to the lot (it is the data controller).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $phone null only for marketplace buyers who signed up by email
 * @property string|null $email
 * @property string|null $address
 * @property CustomerSource $source
 * @property list<string>|null $tags
 * @property int|null $budget_max
 * @property string|null $notes
 * @property bool $consent_whatsapp
 * @property Carbon|null $last_seen_at
 */
class LotCustomer extends Model
{
    use BelongsToLot, HasUlids, Notifiable, SoftDeletes, StoresUtc;

    public const TAGS = ['hot', 'cash buyer', 'instalment', 'trade-in', 'repeat'];

    protected $fillable = ['lot_id', 'user_id', 'name', 'phone', 'email', 'address', 'source', 'tags', 'budget_max', 'notes', 'consent_whatsapp', 'last_seen_at'];

    protected $hidden = ['id', 'lot_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'source' => CustomerSource::class,
            'tags' => 'array',
            'budget_max' => 'integer',
            'consent_whatsapp' => 'boolean',
            'last_seen_at' => 'datetime',
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

    public function routeNotificationForPhone(): ?string
    {
        return $this->phone;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalkIn, $this> */
    public function walkIns(): HasMany
    {
        return $this->hasMany(WalkIn::class);
    }

    /** @return HasMany<SalesOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** @return HasMany<FollowUpTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }
}
