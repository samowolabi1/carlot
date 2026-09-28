<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Appointments\Actions\CancelAppointment;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Appointments\Support\IcsCalendar;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's own bookings. Links in WhatsApp and SMS messages are signed, so a booking
 * can be opened, added to a calendar or cancelled from them without signing in.
 */
class CustomerBookingController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $appointments = Appointment::withoutGlobalScopes()
            ->where('customer_id', $request->user()->id)
            ->latest('starts_at')
            ->limit(100)
            ->get();

        $lots = Lot::withTrashed()->whereIn('id', $appointments->pluck('lot_id')->unique())->get()->keyBy('id');
        $map = fn (Appointment $a) => $this->summary($a, $lots[$a->lot_id]);

        return Inertia::render('Bookings/Index', [
            'upcoming' => $appointments->filter->isUpcoming()->sortBy('starts_at')->values()->map($map),
            'past' => $appointments->reject->isUpcoming()->values()->map($map),
        ])->withViewData(['meta' => ['title' => 'Your bookings', 'robots' => 'noindex']]);
    }

    public function show(Request $request, Appointment $appointment): InertiaResponse
    {
        $this->authorizeAccess($request, $appointment);
        $lot = Lot::withTrashed()->findOrFail($appointment->lot_id);
        $staff = $appointment->staff()->value('name');
        $signed = $request->hasValidSignature();
        $expires = $appointment->starts_at->copy()->addDays(30);

        return Inertia::render('Bookings/Show', [
            'booking' => [
                ...$this->summary($appointment, $lot),
                'staff' => $staff ? strtok($staff, ' ') : null,
                'notes' => $appointment->notes,
                'whatsapp_reminders' => $appointment->whatsapp_reminders,
            ],
            'lot' => MarketplacePresenter::lot($lot),
            'justBooked' => $appointment->created_at->gt(now()->subMinutes(2)) && ! $signed,
            'links' => [
                'calendar' => $signed ? URL::signedRoute('bookings.calendar', $appointment, $expires) : route('bookings.calendar', $appointment),
                'cancel' => $signed ? URL::signedRoute('bookings.cancel', $appointment, $expires) : route('bookings.cancel', $appointment),
                // Rescheduling picks a new slot on the booking page, which needs a signed-in buyer.
                'reschedule' => route('bookings.create', ['lot' => $lot->slug, 'reschedule' => $appointment->ulid]),
            ],
        ])->withViewData(['meta' => ['title' => 'Your booking', 'robots' => 'noindex']]);
    }

    public function update(Request $request, Appointment $appointment, RescheduleAppointment $reschedule): RedirectResponse
    {
        abort_unless($request->user()->id === $appointment->customer_id, 403);

        $data = $request->validate(['starts_at' => ['required', 'date']]);
        $reschedule->run($appointment, CarbonImmutable::parse($data['starts_at']), $request->user());

        return redirect()->route('bookings.show', $appointment)->with('success', 'Booking moved. The lot has been told.');
    }

    public function cancel(Request $request, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        $this->authorizeAccess($request, $appointment);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $cancel->run($appointment, $appointment->customer, $data['reason'] ?? null);

        return back()->with('success', 'Booking cancelled. The lot has been told.');
    }

    public function calendar(Request $request, Appointment $appointment): Response
    {
        $this->authorizeAccess($request, $appointment);
        $lot = Lot::withTrashed()->findOrFail($appointment->lot_id);

        return response(IcsCalendar::for($appointment, $lot, AppointmentText::what($appointment)." at {$lot->name}"), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="lotlink-visit.ics"',
        ]);
    }

    private function authorizeAccess(Request $request, Appointment $appointment): void
    {
        abort_unless($request->hasValidSignature() || $request->user()?->id === $appointment->customer_id, 403);
    }

    private function summary(Appointment $a, Lot $lot): array
    {
        $vehicle = $a->vehicle()->with(['make', 'model', 'cover'])->first();

        return [
            'ulid' => $a->ulid,
            'type' => $a->type->label(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'when' => AppointmentText::when($a, $lot),
            'starts_at' => $a->starts_at->toIso8601String(),
            'upcoming' => $a->isUpcoming(),
            'car' => $vehicle ? ['title' => $vehicle->title(), 'url' => $vehicle->publicPath(), 'image' => MarketplacePresenter::image($vehicle->cover)] : null,
            'lot' => ['name' => $lot->name, 'slug' => $lot->slug, 'city' => $lot->city, 'directions_url' => $lot->directionsUrl()],
            'url' => route('bookings.show', $a),
            'can_cancel' => $a->isUpcoming(),
            'cancel_reason' => $a->status === AppointmentStatus::Cancelled ? $a->cancel_reason : null,
        ];
    }
}
