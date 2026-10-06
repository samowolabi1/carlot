<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Appointments\Actions\BookAppointment;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Fields;
use App\Domain\Support\Input;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's "Book a visit" flow (design 12). */
class BookingController extends Controller
{
    public function create(Request $request, Lot $lot, SlotGenerator $slots): Response
    {
        abort_unless($lot->status === LotStatus::Active, 404);

        $vehicle = $request->filled('car')
            ? Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower(Input::text($request, 'car')))->where('vehicles.lot_id', $lot->id)->with(['make', 'model', 'lot', 'cover'])->first()
            : null;

        $reschedule = null;

        if ($request->filled('reschedule')) {
            $reschedule = Appointment::withoutGlobalScopes()->where('ulid', strtolower(Input::text($request, 'reschedule')))->where('lot_id', $lot->id)->first();
            abort_unless($reschedule && $request->user()->id === $reschedule->customer_id && $reschedule->isUpcoming(), 404);
            $vehicle ??= $reschedule->vehicle_id ? Vehicle::withoutGlobalScope('lot')->with(['make', 'model', 'lot', 'cover'])->find($reschedule->vehicle_id) : null;
        }

        return Inertia::render('Bookings/Book', [
            'lot' => ['slug' => $lot->slug, 'name' => $lot->name, 'city' => $lot->city, 'initials' => $lot->initials(), 'logo_url' => $lot->logo_url],
            'car' => $vehicle ? MarketplacePresenter::card($vehicle) : null,
            'types' => AppointmentType::options(),
            'days' => $slots->days($lot, ignore: $reschedule),
            'reschedule' => $reschedule ? ['ulid' => $reschedule->ulid, 'type' => $reschedule->type->value, 'starts_at' => $reschedule->starts_at->toIso8601String()] : null,
            'defaultType' => AppointmentType::tryFrom(Input::query($request, 'type'))->value ?? 'viewing',
        ])->withViewData(['meta' => ['title' => "Book a visit — {$lot->name}", 'robots' => 'noindex']]);
    }

    public function store(Request $request, BookAppointment $book): RedirectResponse
    {
        $data = $request->validate([
            'lot' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(AppointmentType::class)],
            'starts_at' => ['required', 'date'],
            'vehicle' => Fields::ulid(required: false),
            'notes' => ['nullable', 'string', 'max:500'],
            'whatsapp_reminders' => ['boolean'],
        ]);

        $lot = Lot::active()->where('slug', $data['lot'])->firstOrFail();
        $appointment = $book->run($lot, $request->user(), $data);

        return redirect()->route('bookings.show', $appointment);
    }

    /** GET /lots/{lot}/slots: bookable times for the next 14 days (TDD routes). */
    public function slots(Lot $lot, SlotGenerator $slots): JsonResponse
    {
        abort_unless($lot->status === LotStatus::Active, 404);

        return response()->json($slots->days($lot));
    }
}
