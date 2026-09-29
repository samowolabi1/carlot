<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Appointments\Actions\BookAppointment;
use App\Domain\Appointments\Actions\CancelAppointment;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ApiPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The buyer's visits and test drives. Times come from `GET /lots/{slug}/slots`; booking goes through `BookAppointment`. */
class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $appointments = Appointment::withoutGlobalScopes()->where('customer_id', $request->user()->id)
            ->latest('starts_at')->limit(100)->get();
        $lots = Lot::withTrashed()->whereIn('id', $appointments->pluck('lot_id')->unique())->get()->keyBy('id');

        return response()->json(['data' => $appointments->map(fn (Appointment $a) => ApiPresenter::appointment($a, $lots[$a->lot_id]))->values()]);
    }

    public function store(Request $request, BookAppointment $book): JsonResponse
    {
        $data = $request->validate([
            'lot' => ['required', 'string'],
            'type' => ['required', Rule::enum(AppointmentType::class)],
            'starts_at' => ['required', 'date'],
            'vehicle' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:500'],
            'whatsapp_reminders' => ['boolean'],
        ]);

        $lot = Lot::active()->where('slug', $data['lot'])->firstOrFail();
        $appointment = $book->run($lot, $request->user(), $data);

        return response()->json(['data' => ApiPresenter::appointment($appointment, $lot)], 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 404);

        return response()->json(['data' => ApiPresenter::appointment($appointment, Lot::withTrashed()->findOrFail($appointment->lot_id))]);
    }

    public function update(Request $request, Appointment $appointment, RescheduleAppointment $reschedule): JsonResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 404);
        $data = $request->validate(['starts_at' => ['required', 'date']]);

        $appointment = $reschedule->run($appointment, CarbonImmutable::parse($data['starts_at']), $request->user());

        return response()->json(['data' => ApiPresenter::appointment($appointment, Lot::withTrashed()->findOrFail($appointment->lot_id))]);
    }

    public function cancel(Request $request, Appointment $appointment, CancelAppointment $cancel): JsonResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $appointment = $cancel->run($appointment, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => ApiPresenter::appointment($appointment, Lot::withTrashed()->findOrFail($appointment->lot_id))]);
    }
}
