<?php

namespace App\Domain\Lots\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Marketplace\Jobs\SyncLotVehiclesToSearch;
use App\Domain\Sharing\Jobs\RenderShareCard;
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
 * @property int|null $plan_id
 * @property Carbon|null $featured_until
 * @property Carbon|null $followers_notified_at
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
    ];

    protected $hidden = ['id', 'owner_id', 'plan_id', 'location'];

    protected function casts(): array
    {
        return [
            'status' => LotStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'booking_auto_confirm' => 'boolean',
            'booking_min_notice_minutes' => 'integer',
            'featured_until' => 'datetime',
            'followers_notified_at' => 'datetime',
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
            if ($lot->wasChanged(['status', 'name', 'city', 'latitude', 'longitude'])) {
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
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Spotlight, $this> */
    public function spotlights(): HasMany
    {
        return $this->hasMany(Spotlight::class);
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
