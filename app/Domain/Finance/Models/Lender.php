<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A bank or finance company that gives car loans through LotLink. It applies (or an admin onboards it),
 * is approved by an admin, and then receives buyers' applications in the lender portal or by its own API.
 *
 * @property int $id
 * @property string $ulid
 * @property string $slug
 * @property string $name
 * @property LenderStatus $status
 * @property LenderType $licence_type
 * @property string $licence_number
 * @property string|null $licence_path
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property string|null $website
 * @property string|null $about
 * @property int $rate_bp yearly interest in basis points (2400 = 24%)
 * @property int $min_amount kobo
 * @property int $max_amount kobo
 * @property int $min_deposit_percent
 * @property list<int> $tenors
 * @property list<string>|null $states null: every state
 * @property LenderIntegration $integration
 * @property string|null $api_url
 * @property string|null $api_key
 * @property string|null $webhook_secret
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 */
class Lender extends Model
{
    use HasUlids;

    public const DISK = 'local';

    public const TENORS = [12, 24, 36, 48, 60];

    protected $fillable = [
        'slug', 'name', 'status', 'licence_type', 'licence_number', 'licence_path', 'contact_name', 'contact_email', 'contact_phone',
        'website', 'about', 'rate_bp', 'min_amount', 'max_amount', 'min_deposit_percent', 'tenors', 'states', 'integration',
        'api_url', 'api_key', 'webhook_secret', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['id', 'licence_path', 'api_key', 'webhook_secret', 'submitted_by', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'status' => LenderStatus::class,
            'licence_type' => LenderType::class,
            'integration' => LenderIntegration::class,
            'tenors' => 'array',
            'states' => 'array',
            'rate_bp' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'min_deposit_percent' => 'integer',
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lender_members')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<LenderMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(LenderMember::class);
    }

    /** @return HasMany<FinanceApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(FinanceApplication::class);
    }

    public function isActive(): bool
    {
        return $this->status === LenderStatus::Active;
    }

    public function roleOf(?User $user): ?LenderRole
    {
        if ($user === null) {
            return null;
        }

        return $this->memberships()->where('user_id', $user->id)->first()?->role;
    }

    /** The yearly rate as people read it: 2400 → "24%", 2250 → "22.5%". */
    public function rateLabel(?int $bp = null): string
    {
        return rtrim(rtrim(number_format(($bp ?? $this->rate_bp) / 100, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * Would this lender consider a loan for this car? Active, lends in the lot's state, the amount is within
     * its range, the deposit is enough and it offers the loan length.
     */
    public function lendsFor(int $priceKobo, int $depositKobo, int $tenor, ?string $state): bool
    {
        $loan = $priceKobo - $depositKobo;

        return $this->isActive()
            && ($this->states === null || $this->states === [] || ($state !== null && in_array($state, $this->states, true)))
            && $loan >= $this->min_amount && $loan <= $this->max_amount
            && $depositKobo * 100 >= $priceKobo * $this->min_deposit_percent
            && in_array($tenor, $this->tenors, true);
    }

    /** One line about the product for buyers: "From 24% a year · ₦1m–₦50m · 12–48 months · 20% deposit". */
    public function productLine(): string
    {
        $tenors = $this->tenors;
        sort($tenors);

        return collect([
            'From '.$this->rateLabel().' a year',
            Money::compact($this->min_amount).'–'.Money::compact($this->max_amount),
            count($tenors) > 1 ? reset($tenors).'–'.end($tenors).' months' : (reset($tenors) ?: '?').' months',
            $this->min_deposit_percent.'% deposit',
        ])->implode(' · ');
    }

    /** A short-lived link to the licence copy, for admins. */
    public function licenceUrl(): ?string
    {
        return $this->licence_path ? URL::temporarySignedRoute('lenders.licence', now()->addMinutes(30), ['lender' => $this->slug]) : null;
    }

    /** @return array<string, int> open applications by status */
    public function openCounts(): array
    {
        return $this->applications()->whereIn('status', array_map(fn (FinanceStatus $s) => $s->value, FinanceStatus::open()))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
    }
}
