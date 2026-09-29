<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Analytics\Support\PricingGuide;
use App\Domain\Inventory\Actions\ChangeVehicleStatus;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Actions\SaveVehicleDetails;
use App\Domain\Inventory\Actions\SaveVehicleIdentity;
use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\Drivetrain;
use App\Domain\Inventory\Enums\DutyStatus;
use App\Domain\Inventory\Enums\FeatureGroup;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Feature;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dealer\VehicleDetailsRequest;
use App\Http\Requests\Dealer\VehicleIdentityRequest;
use App\Http\Requests\Dealer\VehiclePriceRequest;
use App\Http\Resources\VehicleResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    /** The add-car flow from the D13–D15 designs. */
    public const STEPS = ['identity', 'details', 'photos', 'price'];

    public function index(Request $request, Lot $lot, VehicleStateMachine $stateMachine): Response
    {
        Gate::authorize('viewAny', [Vehicle::class, $lot]);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VehicleStatus::class)],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $vehicles = Vehicle::query()
            ->with(['make', 'model', 'cover'])
            ->withCount(['media as ready_media_count' => fn ($q) => $q->where('status', 'ready')])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function (Builder $q, string $term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn (Builder $q) => $q
                    ->where('vin', 'like', $like)
                    ->orWhere('trim', 'like', $like)
                    ->orWhere('year', $term)
                    ->orWhereHas('make', fn (Builder $q) => $q->where('name', 'like', $like))
                    ->orWhereHas('model', fn (Builder $q) => $q->where('name', 'like', $like)));
            })
            ->orderByRaw("case status when 'draft' then 0 when 'available' then 1 when 'reserved' then 2 when 'hidden' then 3 else 4 end")
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        // Cars on an open Lot Manager order link to it instead of offering "Mark sold".
        $orders = SalesOrder::query()->whereIn('vehicle_id', collect($vehicles->items())->pluck('id'))
            ->whereIn('status', OrderStatus::open())
            ->get(['ulid', 'order_no', 'vehicle_id'])
            ->keyBy('vehicle_id');

        $vehicles = $vehicles->through(fn (Vehicle $v) => [
            'ulid' => $v->ulid,
            'title' => $v->title() ?: 'Untitled draft',
            'vin_tail' => $v->vinTail(),
            'thumb_url' => $v->cover?->thumbUrl(),
            'photos' => $v->ready_media_count,
            'price' => $v->formattedPrice(),
            'status' => $v->status->value,
            'days_listed' => $v->daysListed(),
            'ageing' => $v->isAgeing(),
            'new_arrival' => $v->isNewArrival(),
            'order' => ($o = $orders->get($v->id)) ? ['ulid' => $o->ulid, 'order_no' => $o->order_no] : null,
            // Shareable once buyers can see it (the lot is approved and the car is live).
            'spotlight_until' => $v->spotlight_until?->isFuture() ? $v->spotlight_until->copy()->setTimezone($lot->timezone)->format('j M') : null,
            'share_url' => $lot->status === LotStatus::Active && in_array($v->status, VehicleStatus::live(), true) && ! $v->isHeld() ? url($v->publicPath()) : null,
            'inspected' => $v->inspection_id !== null,
            // Off the marketplace while LotLink looks at reports or signals (TDD M14).
            'held' => $v->isHeld() ? ($v->held_reason ?: 'Held for review by LotLink') : null,
            // Quick actions on the stock list. Drafts are finished in the add-car flow,
            // and selling goes through Lot Manager, so neither appears here.
            'next_statuses' => $v->status === VehicleStatus::Draft || $v->isHeld() ? [] : array_map(fn (VehicleStatus $s) => $s->value, array_values(array_filter(
                $stateMachine->allowedFrom($v->status),
                fn (VehicleStatus $s) => $s !== VehicleStatus::Sold,
            ))),
        ]);

        $counts = Vehicle::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Dealer/Vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => ['status' => $filters['status'] ?? null, 'q' => $filters['q'] ?? ''],
            'counts' => [
                'all' => (int) $counts->sum(),
                ...collect(VehicleStatus::cases())->mapWithKeys(fn (VehicleStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            ],
            'stats' => [
                'sold_this_month' => Vehicle::where('status', VehicleStatus::Sold)->where('sold_at', '>=', now($lot->timezone)->startOfMonth()->utc())->count(),
                'ageing' => Vehicle::where('status', VehicleStatus::Available)->where('listed_at', '<=', now()->subDays(Vehicle::AGEING_DAYS))->count(),
            ],
            'canManage' => $request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager),
        ]);
    }

    public function create(Lot $lot): Response
    {
        Gate::authorize('create', [Vehicle::class, $lot]);

        return $this->wizard($lot, null, 'identity');
    }

    public function store(VehicleIdentityRequest $request, Lot $lot, SaveVehicleIdentity $save): RedirectResponse
    {
        $vehicle = $save->run($lot, $request->user(), $request->validated());

        return redirect()->route('dealer.vehicles.edit', [$lot, $vehicle, 'details']);
    }

    public function edit(Lot $lot, Vehicle $vehicle, string $step): Response
    {
        abort_unless(in_array($step, self::STEPS, true), 404);
        Gate::authorize('update', $vehicle);

        return $this->wizard($lot, $vehicle, $step);
    }

    public function updateIdentity(VehicleIdentityRequest $request, Lot $lot, Vehicle $vehicle, SaveVehicleIdentity $save): RedirectResponse
    {
        $save->run($lot, $request->user(), $request->validated(), $vehicle);

        return $this->next($request, $lot, $vehicle, 'identity');
    }

    public function updateDetails(VehicleDetailsRequest $request, Lot $lot, Vehicle $vehicle, SaveVehicleDetails $save): RedirectResponse
    {
        $save->run($vehicle, $request->details());

        return $this->next($request, $lot, $vehicle, 'details');
    }

    public function updatePrice(VehiclePriceRequest $request, Lot $lot, Vehicle $vehicle, SaveVehiclePrice $save, PublishVehicle $publish): RedirectResponse
    {
        $price = Money::fromMajor($request->integer('price'));

        if ($price !== $vehicle->price) {
            Gate::authorize('changePrice', $vehicle);
        }

        $save->run($vehicle, $request->user(), $price, $request->boolean('negotiable'));

        if ($request->boolean('publish')) {
            Gate::authorize('publish', $vehicle);
            $publish->run($vehicle);

            return redirect()->route('dealer.vehicles.index', $lot)->with('success', "{$vehicle->load(['make', 'model'])->title()} is live.");
        }

        return back()->with('success', $vehicle->status === VehicleStatus::Draft ? 'Draft saved.' : 'Price saved.');
    }

    public function updateStatus(Request $request, Lot $lot, Vehicle $vehicle, ChangeVehicleStatus $change): RedirectResponse
    {
        Gate::authorize('changeStatus', $vehicle);

        $data = $request->validate(['status' => ['required', Rule::enum(VehicleStatus::class)]]);
        $change->run($vehicle, VehicleStatus::from($data['status']));

        return back()->with('success', 'Status updated.');
    }

    public function destroy(Lot $lot, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('delete', $vehicle);

        abort_if($vehicle->status === VehicleStatus::Sold, 422, 'Sold cars stay in your records.');

        $vehicle->delete();

        return redirect()->route('dealer.vehicles.index', $lot)->with('success', 'Car removed from your stock.');
    }

    private function wizard(Lot $lot, ?Vehicle $vehicle, string $step): Response
    {
        $vehicle?->load(['make', 'model', 'media', 'features']);

        return Inertia::render('Dealer/Vehicles/Wizard', [
            'step' => $step,
            'steps' => self::STEPS,
            'vehicle' => $vehicle ? new VehicleResource($vehicle) : null,
            'makes' => fn () => $step === 'identity' ? $this->catalogue() : [],
            'options' => fn () => $step === 'details' ? $this->detailOptions() : [],
            'missing' => fn () => $vehicle && $step === 'price' ? app(PublishVehicle::class)->missing($vehicle) : [],
            // TDD M15: what similar cars are listed for across LotLink (whole naira).
            'guide' => fn () => $vehicle && $step === 'price' && ($g = PricingGuide::for($vehicle)) ? [
                'low' => intdiv($g['low'], 100), 'median' => intdiv($g['median'], 100), 'high' => intdiv($g['high'], 100), 'count' => $g['count'],
            ] : null,
            'canChangePrice' => $vehicle ? request()->user()->can('changePrice', $vehicle) : true,
            'uploadsDirect' => config('filesystems.disks.'.config('lotlink.upload_disk').'.driver') === 's3',
        ]);
    }

    private function next(Request $request, Lot $lot, Vehicle $vehicle, string $step): RedirectResponse
    {
        if ($request->boolean('wizard')) {
            $next = self::STEPS[array_search($step, self::STEPS, true) + 1] ?? null;

            if ($next) {
                return redirect()->route('dealer.vehicles.edit', [$lot, $vehicle, $next]);
            }
        }

        return back()->with('success', 'Saved.');
    }

    /** Makes with their models, for the make/model pickers. Includes unreviewed models so a dealer sees what they added. */
    private function catalogue(): array
    {
        return Make::query()
            ->with(['models' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Make $make) => [
                'id' => $make->id,
                'name' => $make->name,
                'models' => $make->models->map(fn ($model) => ['id' => $model->id, 'name' => $model->name])->values(),
            ])
            ->all();
    }

    private function detailOptions(): array
    {
        return [
            'body_types' => BodyType::options(),
            'conditions' => VehicleCondition::options(),
            'transmissions' => Transmission::options(),
            'fuels' => FuelType::options(),
            'drivetrains' => Drivetrain::options(),
            'duty_statuses' => DutyStatus::options(),
            'features' => collect(FeatureGroup::cases())->map(fn (FeatureGroup $group) => [
                'group' => $group->label(),
                'items' => Feature::where('group', $group)->orderBy('name')->get(['id', 'name']),
            ])->all(),
        ];
    }
}
