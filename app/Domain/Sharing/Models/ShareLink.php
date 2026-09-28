<?php

namespace App\Domain\Sharing\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Enums\SharePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A short, tracked link to a car or a lot: /c/{code}. Public by design, so it is not
 * scoped to a lot; dealers only ever see counts for their own lot.
 *
 * @property int $id
 * @property string $code
 * @property int|null $vehicle_id
 * @property int $lot_id
 * @property int|null $user_id
 * @property SharePlatform $platform
 * @property int $clicks
 * @property Carbon|null $last_clicked_at
 */
class ShareLink extends Model
{
    protected $fillable = ['code', 'vehicle_id', 'lot_id', 'user_id', 'platform'];

    protected $hidden = ['id', 'vehicle_id', 'lot_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'platform' => SharePlatform::class,
            'clicks' => 'integer',
            'last_clicked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShareLink $link): void {
            $link->code ??= self::newCode();
        });
    }

    /** 8 characters without look-alikes (0/O, 1/l/I), easy to read out or type. */
    public static function newCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function url(): string
    {
        return route('share.go', $this->code);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function isBot(?string $userAgent): bool
    {
        // Link-preview fetchers (WhatsApp, Facebook, X, Telegram, Slack) and crawlers.
        return $userAgent === null || $userAgent === ''
            || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|preview|embedly|headless|curl|wget|python|http-client/i', $userAgent);
    }

    public function registerClick(?string $userAgent): void
    {
        if (self::isBot($userAgent)) {
            return;
        }

        // One atomic UPDATE, so simultaneous clicks all count.
        self::whereKey($this->id)->increment('clicks', 1, ['last_clicked_at' => now()]);
    }
}
