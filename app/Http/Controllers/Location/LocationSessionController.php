<?php

namespace App\Http\Controllers\Location;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Location\Actions\EndLocationSession;
use App\Domain\Location\Actions\StartLocationSession;
use App\Domain\Location\Actions\UpdateLocation;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Live location for a booking (TDD M8, design 14), for the buyer and the lot's team. */
class LocationSessionController extends Controller
{
    public function start(Request $request, Appointment $appointment, StartLocationSession $start): RedirectResponse
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', Rule::in(LocationSession::MINUTES)]])['minutes'];
        $session = $start->run($appointment, $request->user(), $minutes);

        return to_route('location.show', $session);
    }

    public function show(Request $request, LocationSession $session): Response
    {
        $role = $this->role($request, $session);
        $appointment = $session->appointment;
        $lot = Lot::withTrashed()->findOrFail($session->lot_id);
        $customer = $appointment->customer;
        $sharerIsCustomer = $session->sharer_side === LocationSession::CUSTOMER;
        // The buyer's pages for the buyer, the calendar for the lot's team.
        $userIsCustomer = $sharerIsCustomer === ($role === 'sharer');
        $lotPhone = $lot->phone ? ['label' => 'Call the lot', 'phone' => $lot->phone, 'display' => PhoneNumber::display($lot->phone)] : null;

        return Inertia::render('Location/Live', [
            'role' => $role,
            'session' => [
                'ulid' => $session->ulid,
                'live' => $session->isLive(),
                'expires_at' => $session->expires_at->toIso8601String(),
                'point' => $session->point(),
                'sharer' => $sharerIsCustomer ? Name::short($customer->name) : $lot->name,
                'sharer_side' => $session->sharer_side,
            ],
            'appointment' => [
                'what' => AppointmentText::what($appointment),
                'when' => AppointmentText::when($appointment, $lot),
                'url' => $userIsCustomer ? route('bookings.show', $appointment) : route('dealer.calendar', $lot),
            ],
            'lot' => ['name' => $lot->name, 'lat' => $lot->latitude, 'lng' => $lot->longitude, 'directions' => $lot->directionsUrl()],
            'call' => $userIsCustomer ? $lotPhone : ['label' => 'Call '.Name::short($customer->name), 'phone' => $customer->phone, 'display' => PhoneNumber::display($customer->phone)],
            'back' => $userIsCustomer ? 'booking' : 'calendar',
        ])->withViewData(['meta' => ['title' => 'Live location', 'robots' => 'noindex']]);
    }

    public function position(Request $request, LocationSession $session, UpdateLocation $update): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $update->run($session, $request->user(), (float) $data['lat'], (float) $data['lng'], isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null);

        return response()->json(['ok' => true, 'live' => $session->isLive()]);
    }

    /** Polling fallback when Reverb isn't running. */
    public function point(Request $request, LocationSession $session): JsonResponse
    {
        $this->role($request, $session);

        return response()->json(['point' => $session->point(), 'live' => $session->isLive()]);
    }

    public function stop(Request $request, LocationSession $session, EndLocationSession $end): RedirectResponse
    {
        abort_unless($session->sharer_id === $request->user()->id, 403);
        $end->run($session);

        return redirect($request->input('back') === 'calendar' ? route('dealer.calendar', Lot::withTrashed()->findOrFail($session->lot_id)) : route('bookings.show', $session->appointment))
            ->with('success', 'You stopped sharing your location.');
    }

    /** "sharer" or "viewer"; anyone else gets a 403. */
    private function role(Request $request, LocationSession $session): string
    {
        $user = $request->user();
        if ($session->sharer_id === $user->id) {
            return 'sharer';
        }

        $side = StartLocationSession::side($user, $session->appointment);
        abort_unless($side !== null && $side !== $session->sharer_side, 403);

        return 'viewer';
    }
}
