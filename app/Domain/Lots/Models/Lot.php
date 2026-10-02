<?php

namespace App\Domain\Lots\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Marketplace\Jobs\SyncLotVehiclesToSearch;
use App\Domain\Sharing\Jobs\RenderShareCard;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Models\Review;
use Database\Factories\LotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $ulid
 * @property int $owner_id
 * @property string $name
 * @property string $slug
 * @property string|null $custom_domain
 * @property string|null $domain_token
 * @property Carbon|null $domain_verified_at
 * @property string|null $tagline
 * @property string|null $about
 * @property string|null $logo_path
 * @property string|null $cover_path
 * @property string|null $brand_color
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $address
 * @property string|null $landmark
 * @property string|null $city
 * @property string|null $state
 * @property string $country
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string $timezone
 * @property bool $booking_auto_confirm
 * @property int $booking_min_notice_minutes
 * @property LotStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $verified_at
 * @property float|null $rating
 * @property int $reviews_count
 * @property int|null $plan_id
 * @property Carbon|null $featured_until
 * @property Carbon|null $followers_notified_at
 * @property bool $accepts_offers
 * @property bool $accepts_trade_ins
 * @property bool $accepts_finance
 * @property int|null $reservation_deposit
 * @property bool $reservation_refundable
 * @property int|null $test_drive_deposit no longer used: LotLink takes no buyer deposits
 * @property string|null $paystack_subaccount
 * @property int|null $onboarded_by the admin who signed the lot up, if one did
 * @property string|null $referral_code
 * @property Carbon|null $daily_summary_sent_on
 * @property-read string|null $logo_url
 * @property-read string|null $cover_url
 * @property-read LotMember $pivot
 * @property-read int|null $members_count
 * @property-read int|null $hours_count
 * @property-read int|null $invitations_count
 */
class Lot extends Model
{
    /** @use HasFactory<LotFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'slug', 'tagline', 'about', 'logo_path', 'cover_path', 'brand_color',
        'phone', 'whatsapp', 'email', 'address', 'landmark', 'city', 'state', 'country',
        'latitude', 'longitude', 'timezone', 'status', 'plan_id', 'booking_auto_confirm', 'booking_min_notice_minutes',
        'accepts_offers', 'accepts_trade_ins', 'accepts_finance', 'reservation_deposit', 'reservation_refundable', 'test_drive_deposit',
    ];

    protected $hidden = ['id', 'owner_id', 'plan_id', 'location', 'paystack_subaccount', 'domain_token'];

    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rating' => 'float',
            'reviews_count' => 'integer',
            'booking_auto_confirm' => 'boolean',
            'booking_min_notice_minutes' => 'integer',
            'featured_until' => 'datetime',
            'followers_notified_at' => 'datetime',
            'accepts_offers' => 'boolean',
            'accepts_trade_ins' => 'boolean',
            'accepts_finance' => 'boolean',
            'reservation_deposit' => 'integer',
            'reservation_refundable' => 'boolean',
            'test_drive_deposit' => 'integer',
            'daily_summary_sent_on' => 'date',
            'domain_verified_at' => 'datetime',
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

    protected static function newFactory(): LotFactory
    {
        return LotFactory::new();
    }

    protected static function booted(): void
    {
        static::updated(function (Lot $lot): void {
            if ($lot->wasChanged(['status', 'name', 'city', 'state', 'latitude', 'longitude', 'plan_id', 'verified_at', 'accepts_offers', 'accepts_trade_ins', 'accepts_finance'])) {
                SyncLotVehiclesToSearch::dispatch($lot->id);
            }

            // Share cards show the lot's name, phone and logo, and exist only while it is live.
            if ($lot->wasChanged(['status', 'name', 'phone', 'logo_path', 'plan_id'])) {
                $lot->vehicles()->withoutGlobalScopes()->whereIn('status', ['available', 'reserved'])->pluck('id')
                    ->each(fn (int $id) => RenderShareCard::refresh($id));
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'lot';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** Buyers following the lot for new stock (TDD M5). @return BelongsToMany<User, $this> */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lot_followers')->withTimestamps();
    }

    /** What the lot has paid LotLink (plans, spotlights); {billingPayment} route bindings. @return HasMany<Payment, $this> */
    public function billingPayments(): HasMany
    {
        return $this->hasMany(Payment::class)->whereIn('purpose', PaymentPurpose::billing());
    }

    /** @return HasMany<Spotlight, $this> */
    public function spotlights(): HasMany
    {
        return $this->hasMany(Spotlight::class);
    }

    /** @return HasMany<LotVerification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(LotVerification::class)->withoutGlobalScopes()->latest('id');
    }

    /** @return HasOne<LotVerification, $this> */
    public function latestVerification(): HasOne
    {
        return $this->hasOne(LotVerification::class)->withoutGlobalScopes()->latestOfMany();
    }

    /**
     * Scoped {review} bindings.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Scoped {socialAccount} bindings.
     *
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** The star rating buyers see: only once there are enough visible reviews (TDD M14). */
    public function publicRating(): ?float
    {
        return $this->reviews_count >= Review::MIN_FOR_RATING && $this->rating !== null ? round($this->rating, 1) : null;
    }

    /** Scoped route bindings for {lead}. @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<TradeIn, $this> */
    public function tradeIns(): HasMany
    {
        return $this->hasMany(TradeIn::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** "PRIMEAB3": shared as /dealer/start?ref=… (TDD M19: lot referrals). */
    public function referralCode(): string
    {
        if ($this->referral_code === null) {
            $this->forceFill(['referral_code' => strtoupper(Str::random(8))])->save();
        }

        return (string) $this->referral_code;
    }

    /** A plan feature such as offers or deposits (spec: monetisation). */
    public function planAllows(string $feature): bool
    {
        return (bool) ($this->plan ?? Plan::default())?->allows($feature);
    }

    /** Buyers can make offers: the lot takes them and its plan includes them. */
    public function takesOffers(): bool
    {
        return $this->accepts_offers && $this->planAllows('offers');
    }

    /** Buyers can send their car for valuation (every plan; the owner can turn it off). */
    public function takesTradeIns(): bool
    {
        return $this->accepts_trade_ins;
    }

    /** Buyers can apply for a car loan on this lot's cars (the owner can turn it off). */
    public function takesFinance(): bool
    {
        return $this->accepts_finance;
    }

    /** The reservation deposit in minor units, or null when reservations are off. */
    public function reservationDeposit(): ?int
    {
        // Buyers pay the lot directly, so there must be an account to pay into.
        return $this->reservation_deposit > 0 && $this->planAllows('deposits') && $this->bankAccounts()->exists() ? $this->reservation_deposit : null;
    }

    public function isFeatured(): bool
    {
        return $this->featured_until?->isFuture() ?? false;
    }

    /** @return BelongsToMany<User, $this, LotMember> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lot_members')
            ->using(LotMember::class)
            ->withPivot(['role', 'accepted_at', 'invited_by'])
            ->withTimestamps();
    }

    /**
     * Bank accounts customers pay into (the lot is paid directly; LotLink never holds car money).
     *
     * @return HasMany<LotBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(LotBankAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * Support tickets the lot opened with LotLink (also the scoped binding for `{supportTicket}`).
     *
     * @return HasMany<SupportTicket, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return HasMany<LotHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(LotHour::class)->orderBy('weekday');
    }

    /** @return HasMany<LotClosure, $this> */
    public function closures(): HasMany
    {
        return $this->hasMany(LotClosure::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<LotInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(LotInvitation::class);
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Lot Manager (M19). Scoped route bindings use these for {customer}, {order},
     * {payment} and {task}.
     *
     * @return HasMany<LotCustomer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(LotCustomer::class);
    }

    /** @return HasMany<SalesOrder, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /** @return HasMany<OrderPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /** @return HasMany<FollowUpTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(FollowUpTask::class);
    }

    /** @param Builder<Lot> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', LotStatus::Active);
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function directionsUrl(): ?string
    {
        return $this->hasLocation()
            ? "https://www.google.com/maps/dir/?api=1&destination={$this->latitude},{$this->longitude}"
            : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)->explode(' ')->filter()->take(2)
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))->implode('');
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo_path ? Storage::disk(config('lotlink.media_disk'))->url($this->logo_path) : null);
    }

    protected function coverUrl(): Attribute
    {
        return Attribute::get(fn () => $this->cover_path ? Storage::disk(config('lotlink.media_disk'))->url($this->cover_path) : null);
    }
}
