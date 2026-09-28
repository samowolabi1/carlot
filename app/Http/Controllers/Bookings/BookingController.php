<?php

namespace App\Http\Controllers\Bookings;

use App\Domain\Appointments\Actions\BookAppointment;
use App\Domain\Appointments\Actions\StartDepositCheckout;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Billing\Actions\FulfilPayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** The buyer's "Book a visit" flow (design 12). */
class BookingController extends Controller
{
    public function create(Request $request, Lot $lot, SlotGenerator $slots): Response
    {
        abort_unless($lot->status === LotStatus::Active, 404);

        $vehicle = $request->filled('car')
            ? Vehicle::query()->marketplace()->where('vehicles.ulid', $request->string('car')->lower()->toString())->where('vehicles.lot_id', $lot->id)->with(['make', 'model', 'lot', 'cover'])->first()
            : null;

        $reschedule = null;

        if ($request->filled('reschedule')) {
            $reschedule = Appointment::withoutGlobalScopes()->where('ulid', $request->string('reschedule')->lower()->toString())->where('lot_id', $lot->id)->first();
            abort_unless($reschedule && $request->user()->id === $reschedule->customer_id && $reschedule->isUpcoming(), 404);
            $vehicle ??= $reschedule->vehicle_id ? Vehicle::withoutGlobalScope('lot')->with(['make', 'model', 'lot', 'cover'])->find($reschedule->vehicle_id) : null;
        }

        return Inertia::render('Bookings/Book', [
            'lot' => ['slug' => $lot->slug, 'name' => $lot->name, 'city' => $lot->city, 'initials' => $lot->initials(), 'logo_url' => $lot->logo_url],
            'car' => $vehicle ? MarketplacePresenter::card($vehicle) : null,
            'types' => AppointmentType::options(),
            'days' => $slots->days($lot, ignore: $reschedule),
            'reschedule' => $reschedule ? ['ulid' => $reschedule->ulid, 'type' => $reschedule->type->value, 'starts_at' => $reschedule->starts_at->toIso8601String()] : null,
            'deposit' => ($d = $lot->testDriveDeposit()) ? Money::format($d, (string) config('lotlink.currency', 'NGN')) : null,
            'defaultType' => AppointmentType::tryFrom((string) $request->query('type'))->value ?? 'viewing',
        ])->withViewData(['meta' => ['title' => "Book a visit — {$lot->name}", 'robots' => 'noindex']]);
    }

    public function store(Request $request, BookAppointment $book, StartDepositCheckout $deposit): SymfonyResponse
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

        // A test drive with a deposit goes to Paystack first; the slot is held meanwhile.
        if ($appointment->status === AppointmentStatus::AwaitingDeposit) {
            return Inertia::location($deposit->run($appointment));
        }

        return redirect()->route('bookings.show', $appointment);
    }

    /** "Pay deposit" again from the booking, if the first checkout was abandoned. */
    public function deposit(Request $request, Appointment $appointment, StartDepositCheckout $deposit): SymfonyResponse
    {
        abort_unless($appointment->customer_id === $request->user()->id, 403);

        return Inertia::location($deposit->run($appointment));
    }

    public function depositCallback(Request $request, FulfilPayment $fulfil): RedirectResponse
    {
        $payment = Payment::where('user_id', $request->user()->id)->where('purpose', PaymentPurpose::Deposit)
            ->where('reference', (string) $request->query('reference', $request->query('trxref', '')))->first();
        $appointment = $payment ? Appointment::withoutGlobalScopes()->find($payment->payable_id) : null;

        if ($payment === null || $appointment === null) {
            return to_route('bookings.index')->with('error', 'We could not find that payment.');
        }

        $payment = $fulfil->run($payment);

        return to_route('bookings.show', $appointment)->with(...match ($payment->status) {
            PaymentStatus::Success => ['success', 'Deposit paid. You\'re booked.'],
            PaymentStatus::Refunded => ['error', 'The slot was released before the payment arrived. Your deposit is being refunded.'],
            PaymentStatus::Pending => ['success', 'We are waiting for Paystack to confirm the payment.'],
            default => ['error', 'The payment did not go through. Try again to keep your slot.'],
        });
    }

    /** GET /lots/{lot}/slots: bookable times for the next 14 days (TDD routes). */
    public function slots(Lot $lot, SlotGenerator $slots): JsonResponse
    {
        abort_unless($lot->status === LotStatus::Active, 404);

        return response()->json($slots->days($lot));
    }
}
