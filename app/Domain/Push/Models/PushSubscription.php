<?php

namespace App\Domain\Push\Models;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser or phone that turned on push notifications. The endpoint is the push service's
 * address for it (Google, Mozilla, Apple); the keys encrypt what we send, so only that browser can read it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $device e.g. "Chrome on Android"
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'device', 'last_used_at'];

    protected $hidden = ['id', 'user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
