<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Appointments\Actions\CancelAppointment;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\StartDepositCheckout;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Appointments\Support\IcsCalendar;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Location\Actions\StartLocationSession;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
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

        $user = $request->user();
        $offers = Offer::withoutGlobalScopes()->where('customer_id', $user->id)->where('status', '!=', OfferStatus::Withdrawn)
            ->with(['vehicle.make', 'vehicle.model'])->latest('id')->limit(30)->get();
        $reservations = Reservation::withoutGlobalScopes()->where('customer_id', $user->id)->where('status', '!=', ReservationStatus::Pending)
            ->with(['vehicle.make', 'vehicle.model', 'payment'])->latest('id')->limit(30)->get();
        $tradeIns = TradeIn::withoutGlobalScopes()->where('customer_id', $user->id)
            ->with(['make', 'model', 'vehicle.make', 'vehicle.model'])->latest('id')->limit(30)->get();
        $dealLots = Lot::withTrashed()->whereIn('id', $offers->pluck('lot_id')->merge($reservations->pluck('lot_id'))->merge($tradeIns->pluck('lot_id'))->unique())->get()->keyBy('id');

        // Open deals first (design 19: "Offers and reservations"), closed ones under Past.
        // An accepted offer that became a reservation shows as the reservation.
        $reservedCars = $reservations->where('status', ReservationStatus::Active)->pluck('vehicle_id')->all();
        $openOffer = fn (Offer $o) => $o->isOpen()
            || ($o->status === OfferStatus::Accepted && $o->closed_at?->gt(now()->subDays(7)) && ! in_array($o->vehicle_id, $reservedCars, true));

        return Inertia::render('Bookings/Index', [
            'upcoming' => $appointments->filter(fn (Appointment $a) => $a->isUpcoming() || $a->status === AppointmentStatus::AwaitingDeposit)->sortBy('starts_at')->values()->map($map),
            'past' => $appointments->reject(fn (Appointment $a) => $a->isUpcoming() || $a->status === AppointmentStatus::AwaitingDeposit)->values()->map($map),
            'offers' => $offers->filter($openOffer)->values()->map(fn (Offer $o) => DealsPresenter::buyerOffer($o, $dealLots[$o->lot_id])),
            'reservations' => $reservations->filter(fn (Reservation $r) => $r->status === ReservationStatus::Active)->values()->map(fn (Reservation $r) => DealsPresenter::buyerReservation($r, $dealLots[$r->lot_id])),
            'tradeIns' => $tradeIns->filter(fn (TradeIn $t) => in_array($t->status, [TradeInStatus::Submitted, TradeInStatus::Valued], true))->values()->map(fn (TradeIn $t) => DealsPresenter::buyerTradeIn($t, $dealLots[$t->lot_id])),
            'pastDeals' => collect()
                ->merge($offers->reject($openOffer)->map(fn (Offer $o) => ['key' => "o{$o->ulid}", 'title' => 'Offer on '.$o->vehicle->title(), 'detail' => $o->money().' · '.$dealLots[$o->lot_id]->name, 'status' => $o->status->label(), 'at' => $o->created_at]))
                ->merge($reservations->reject(fn (Reservation $r) => $r->status === ReservationStatus::Active)->map(fn (Reservation $r) => ['key' => "r{$r->ulid}", 'title' => 'Reservation: '.$r->vehicle->title(), 'detail' => $r->money().' deposit · '.$dealLots[$r->lot_id]->name.($r->payment?->refunded_at ? ' · refunded' : ''), 'status' => $r->status->label(), 'at' => $r->created_at]))
                ->merge($tradeIns->reject(fn (TradeIn $t) => in_array($t->status, [TradeInStatus::Submitted, TradeInStatus::Valued], true))->map(fn (TradeIn $t) => ['key' => "t{$t->ulid}", 'title' => 'Trade-in: '.$t->title(), 'detail' => ($t->estimate() ?? '').' · '.$dealLots[$t->lot_id]->name, 'status' => $t->status->label(), 'at' => $t->created_at]))
                ->sortByDesc('at')->take(20)->values()->map(fn (array $d) => [...$d, 'at' => $d['at']?->diffForHumans()]),
        ])->withViewData(['meta' => ['title' => 'Bookings and offers', 'robots' => 'noindex']]);
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
            // Live location (TDD M8): share yours on the way, or follow the lot's.
            'location' => [
                'can_share' => $request->user()?->id === $appointment->customer_id && StartLocationSession::canShare($appointment),
                'live' => LocationSession::withoutGlobalScopes()->live()->where('appointment_id', $appointment->id)->get()
                    ->map(fn (LocationSession $s) => ['ulid' => $s->ulid, 'mine' => $s->sharer_side === LocationSession::CUSTOMER, 'expires' => $s->expires_at->copy()->setTimezone($lot->timezone)->format('H:i')])->values(),
            ],
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
            'deposit' => $this->deposit($a, $lot),
        ];
    }

    /** A test drive's refundable deposit: due (with the time left to pay), paid or refunded. @return array<string, mixed>|null */
    private function deposit(Appointment $a, Lot $lot): ?array
    {
        $payment = $a->deposit_payment_id ? Payment::find($a->deposit_payment_id) : null;

        if ($a->status === AppointmentStatus::AwaitingDeposit) {
            return [
                'state' => 'due',
                'amount' => Money::format((int) $lot->testDriveDeposit(), (string) config('lotlink.currency', 'NGN')),
                'left' => DealsPresenter::left($a->created_at?->copy()->addMinutes(StartDepositCheckout::HOLD_MINUTES)),
                'pay_url' => route('bookings.deposit', $a),
            ];
        }

        if ($payment === null || ! in_array($payment->status, [PaymentStatus::Success, PaymentStatus::Refunded], true)) {
            return null;
        }

        return ['state' => $payment->status === PaymentStatus::Refunded ? 'refunded' : 'paid', 'amount' => $payment->money(), 'left' => null, 'pay_url' => null];
    }
}
