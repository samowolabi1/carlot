<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Read-only lot views for staff in the app: stock (dealer fields via `VehicleResource`, never costs)
 * and the calendar. Adding and editing cars stays on the web for now.
 */
class StockController extends Controller
{
    public function vehicles(Request $request, Lot $lot): JsonResponse
    {
        Gate::authorize('viewAny', [Vehicle::class, $lot]);
        $status = $request->validate(['status' => ['nullable', Rule::enum(VehicleStatus::class)]])['status'] ?? null;

        $vehicles = Vehicle::query()->with(['make', 'model', 'media'])
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->latest('updated_at')->paginate(25);

        return response()->json([
            'data' => VehicleResource::collection($vehicles->getCollection())->resolve($request),
            'meta' => ['current_page' => $vehicles->currentPage(), 'last_page' => $vehicles->lastPage(), 'total' => $vehicles->total()],
        ]);
    }

    /** Visits between `from` and `to` (dates in the lot's time zone; default: today and the next 7 days). */
    public function appointments(Request $request, Lot $lot): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = CarbonImmutable::parse($data['from'] ?? 'today', $lot->timezone)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $lot->timezone)->endOfDay() : $from->addDays(8);

        $appointments = Appointment::query()->with(['customer', 'staff', 'vehicle.make', 'vehicle.model'])
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::AwaitingDeposit])
            ->orderBy('starts_at')->limit(300)->get();

        return response()->json(['data' => $appointments->map(fn (Appointment $a) => [
            'ulid' => $a->ulid,
            'type' => $a->type->value,
            'type_label' => $a->type->label(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'customer' => $a->customer->name ?? 'Buyer',
            'staff' => $a->staff?->name,
            'car' => $a->vehicle ? ['ulid' => $a->vehicle->ulid, 'title' => $a->vehicle->title()] : null,
            'notes' => $a->notes,
        ])->values(), 'timezone' => $lot->timezone]);
    }
}
