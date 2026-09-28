<?php

namespace App\Domain\Deals\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\TradeInCondition;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\Money;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A buyer's car offered in part-exchange (TDD M12). Photos live on the private disk and are
 * shown through short-lived signed URLs; the lot replies with a low–high estimate.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $lead_id
 * @property int $customer_id
 * @property int|null $vehicle_id
 * @property int $make_id
 * @property int|null $vehicle_model_id
 * @property string|null $model_name
 * @property int $year
 * @property int $mileage_km
 * @property TradeInCondition $condition
 * @property string|null $notes
 * @property list<string> $photos
 * @property int|null $estimate_low
 * @property int|null $estimate_high
 * @property string $currency
 * @property string|null $valuation_note
 * @property int|null $valued_by
 * @property Carbon|null $valued_at
 * @property TradeInStatus $status
 * @property int|null $appointment_id
 * @property Carbon|null $created_at
 */
class TradeIn extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const MAX_PHOTOS = 8;

    public const DISK = 'local';

    protected $fillable = ['lot_id', 'lead_id', 'customer_id', 'vehicle_id', 'make_id', 'vehicle_model_id', 'model_name', 'year', 'mileage_km', 'condition', 'notes', 'photos', 'estimate_low', 'estimate_high', 'currency', 'valuation_note', 'valued_by', 'valued_at', 'status', 'appointment_id'];

    protected $hidden = ['id', 'lot_id', 'lead_id', 'customer_id', 'vehicle_id', 'make_id', 'vehicle_model_id', 'valued_by', 'appointment_id', 'photos'];

    protected function casts(): array
    {
        return [
            'condition' => TradeInCondition::class,
            'status' => TradeInStatus::class,
            'photos' => 'array',
            'year' => 'integer',
            'mileage_km' => 'integer',
            'estimate_low' => 'integer',
            'estimate_high' => 'integer',
            'valued_at' => 'datetime',
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

    /** @return BelongsTo<Make, $this> */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScope('lot')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScope('lot');
    }

    /** "2014 Honda Civic". */
    public function title(): string
    {
        return trim($this->year.' '.($this->make->name ?? '').' '.($this->model->name ?? $this->model_name ?? ''));
    }

    /** "₦3.8m–₦4.3m" style range, in full naira. */
    public function estimate(): ?string
    {
        if ($this->estimate_low === null || $this->estimate_high === null) {
            return null;
        }

        return Money::compact($this->estimate_low, $this->currency).'–'.Money::compact($this->estimate_high, $this->currency);
    }

    /** @return list<string> Signed links to the photos, valid for 30 minutes. */
    public function photoUrls(): array
    {
        return array_map(
            fn (int $i) => URL::temporarySignedRoute('trade-ins.photo', now()->addMinutes(30), ['tradeIn' => $this->ulid, 'index' => $i]),
            array_keys($this->photos ?? []),
        );
    }
}
