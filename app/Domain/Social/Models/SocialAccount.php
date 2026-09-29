<?php

namespace App\Domain\Social\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A lot's Facebook Page or Instagram Business account (TDD M9 auto-post). The page token is
 * encrypted at rest and never leaves the server.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property string $provider facebook or instagram
 * @property string $page_id
 * @property string $name
 * @property string $token
 * @property Carbon|null $expires_at
 * @property bool $auto_post
 * @property int|null $connected_by
 * @property Carbon|null $last_error_at
 * @property string|null $last_error
 */
class SocialAccount extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const PROVIDERS = ['facebook' => 'Facebook Page', 'instagram' => 'Instagram'];

    protected $fillable = ['lot_id', 'provider', 'page_id', 'name', 'token', 'expires_at', 'auto_post', 'connected_by', 'last_error_at', 'last_error'];

    protected $hidden = ['id', 'lot_id', 'token', 'connected_by'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'expires_at' => 'datetime', 'auto_post' => 'boolean', 'last_error_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<SocialPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
