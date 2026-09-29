<?php

namespace App\Domain\Advertising\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdCta;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An advert a lot pays LotLink for: a homepage banner or a search banner. Bought with
 * `CreateAdCampaign`, checked by an admin (`ReviewAdCampaign`), served by `AdServer`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property AdPlacement $placement
 * @property AdStatus $status
 * @property string $headline
 * @property string|null $subtext
 * @property AdCta $cta
 * @property int|null $vehicle_id
 * @property string|null $image_path
 * @property array{make_id?: int|null, body_type?: string|null, city?: string|null}|null $targeting
 * @property int $days
 * @property Carbon $requested_start
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $price
 * @property string $currency
 * @property int|null $payment_id
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property int $impressions
 * @property int $clicks
 * @property Carbon $created_at
 */
class AdCampaign extends Model
{
    use BelongsToLot, HasUlids;

    public const DAYS = [7, 14, 30];

    /** How far ahead a start date can be booked. */
    public const BOOK_AHEAD_DAYS = 60;

    protected $fillable = [
        'lot_id', 'placement', 'status', 'headline', 'subtext', 'cta', 'vehicle_id', 'image_path', 'targeting', 'days',
        'requested_start', 'starts_at', 'ends_at', 'price', 'currency', 'payment_id', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'payment_id', 'created_by', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'placement' => AdPlacement::class,
            'status' => AdStatus::class,
            'cta' => AdCta::class,
            'targeting' => 'array',
            'days' => 'integer',
            'requested_start' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'price' => 'integer',
            'impressions' => 'integer',
            'clicks' => 'integer',
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

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Approved and running right now.
     *
     * @param  Builder<AdCampaign>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->withoutGlobalScope('lot')
            ->where($query->qualifyColumn('status'), AdStatus::Approved)
            ->where($query->qualifyColumn('starts_at'), '<=', now())
            ->where($query->qualifyColumn('ends_at'), '>', now());
    }

    /** What the lot sees: awaiting payment, in review, scheduled, live, ended, rejected or removed. */
    public function state(): string
    {
        return match ($this->status) {
            AdStatus::Draft => 'unpaid',
            AdStatus::InReview => 'in_review',
            AdStatus::Rejected => 'rejected',
            AdStatus::Removed => 'removed',
            AdStatus::Approved => match (true) {
                $this->starts_at?->isFuture() ?? false => 'scheduled',
                $this->ends_at?->isPast() ?? false => 'ended',
                default => 'live',
            },
        };
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'unpaid' => 'Waiting for payment',
            'in_review' => 'Being checked by LotLink',
            'scheduled' => 'Scheduled',
            'live' => 'Live',
            'ended' => 'Ended',
            'rejected' => 'Not approved (refunded)',
            default => 'Removed by LotLink',
        };
    }

    /** The banner image: the uploaded creative, else the car's cover photo. */
    public function imageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('lotlink.media_disk'))->url($this->image_path);
        }

        return $this->vehicle?->cover?->urls()[1600] ?? null;
    }

    public function money(): string
    {
        return (string) Money::format($this->price, $this->currency);
    }

    public function ctr(): ?float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 1) : null;
    }
}
