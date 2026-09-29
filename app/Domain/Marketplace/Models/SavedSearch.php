<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buyer's search with alerts (TDD M4): filters as /cars reads them (whole naira), and how to
 * hear about new matches. At most one alert per search every 6 hours.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property string $channel phone (WhatsApp/SMS), mail or app
 * @property Carbon|null $last_notified_at
 * @property Carbon $created_at
 */
class SavedSearch extends Model
{
    use HasUlids, StoresUtc;

    public const CHANNELS = ['phone' => 'WhatsApp', 'mail' => 'Email', 'app' => 'In the app only'];

    public const MAX_PER_USER = 10;

    public const ALERT_EVERY_HOURS = 6;

    protected $fillable = ['user_id', 'name', 'filters', 'channel', 'last_notified_at'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'last_notified_at' => 'datetime'];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(): string
    {
        return route('cars.index', $this->filters);
    }
}
