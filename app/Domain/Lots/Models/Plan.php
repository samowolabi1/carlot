<?php

namespace App\Domain\Lots\Models;

use App\Domain\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $price
 * @property string $currency
 * @property int|null $listing_limit
 * @property int|null $staff_limit
 * @property int $free_spotlights
 * @property string $interval
 * @property string|null $provider_plan_code Paystack plan code (PLN_…)
 * @property string|null $flutterwave_plan_id Flutterwave payment plan id
 * @property bool $self_serve
 * @property int $sort
 * @property array<string, int|bool|null>|null $features
 */
class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price', 'currency', 'interval', 'provider_plan_code', 'flutterwave_plan_id', 'listing_limit', 'staff_limit', 'features', 'free_spotlights', 'self_serve', 'sort'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'features' => 'array',
            'free_spotlights' => 'integer',
            'self_serve' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** A numeric plan limit from features (e.g. open_orders); null means unlimited. */
    public function limit(string $feature): ?int
    {
        $value = $this->features[$feature] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** A yes/no plan feature, e.g. share_cards. Missing means yes, so older plans keep working. */
    public function allows(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? true);
    }

    public function isFree(): bool
    {
        return $this->code === config('lotlink.billing.free_plan');
    }

    public static function free(): self
    {
        return static::where('code', config('lotlink.billing.free_plan'))->firstOrFail();
    }

    public static function default(): ?self
    {
        return static::where('code', config('lotlink.default_plan'))->first();
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The recurring plan at a payment provider, if one was made there. */
    public function codeFor(string $provider): ?string
    {
        return match ($provider) {
            'paystack' => $this->provider_plan_code,
            'flutterwave' => $this->flutterwave_plan_id,
            default => null,
        };
    }

    public function setCodeFor(string $provider, string $code): void
    {
        $this->forceFill([$provider === 'flutterwave' ? 'flutterwave_plan_id' : 'provider_plan_code' => $code])->save();
    }
}
