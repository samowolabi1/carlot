<?php

namespace App\Http\Controllers\Deals;

use App\Domain\Billing\Actions\FulfilPayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Deals\Actions\StartReservation;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** "Reserve with deposit" (design 11) and the Paystack callback. */
class ReservationController extends Controller
{
    public function create(Request $request, string $car): Response
    {
        $vehicle = DealsPresenter::car($car);
        $lot = $vehicle->lot;
        $deposit = $lot->reservationDeposit();
        abort_if($deposit === null || DealsPresenter::forCar($vehicle, $request->user())['reserve'] === null, 404);

        $offer = Offer::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $request->user()->id)
            ->where('status', OfferStatus::Accepted)->latest('id')->first();

        return Inertia::render('Deals/Reserve', [
            'car' => MarketplacePresenter::card($vehicle),
            'lot' => ['name' => $lot->name],
            'deposit' => Money::format($deposit, $vehicle->currency),
            'agreed' => $offer ? $offer->money((int) $offer->agreedAmount()) : null,
            'hours' => Reservation::HOURS,
            // "Held until Wed 30 Sep, 2:00pm" for each choice, in the lot's time.
            'until' => collect(Reservation::HOURS)->mapWithKeys(fn (int $h) => [$h => now()->addHours($h)->setTimezone($lot->timezone)->format('D j M, g:ia')]),
            'refundable' => $lot->reservation_refundable,
        ])->withViewData(['meta' => ['title' => 'Reserve — '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(Request $request, string $car, StartReservation $start): HttpResponse
    {
        $data = $request->validate([
            'hours' => ['required', 'integer', Rule::in(Reservation::HOURS)],
            'channel' => ['nullable', 'in:card,transfer,ussd'],
        ]);

        $result = $start->run($request->user(), DealsPresenter::car($car), (int) $data['hours'], $data['channel'] ?? null);

        return Inertia::location($result['url']);
    }

    public function callback(Request $request, FulfilPayment $fulfil): RedirectResponse
    {
        $payment = Payment::where('user_id', $request->user()->id)->where('purpose', PaymentPurpose::Reservation)
            ->where('reference', (string) $request->query('reference', $request->query('trxref', '')))->first();

        if ($payment === null) {
            return redirect(route('bookings.index').'#offers')->with('error', 'We could not find that payment.');
        }

        $payment = $fulfil->run($payment);

        return redirect(route('bookings.index').'#offers')->with(...match ($payment->status) {
            PaymentStatus::Success => ['success', 'Paid. The car is reserved for you.'],
            PaymentStatus::Refunded => ['error', 'Someone else took the car while you paid. Your deposit is being refunded.'],
            PaymentStatus::Pending => ['success', 'We are waiting for Paystack to confirm the payment.'],
            default => ['error', 'The payment did not go through. You have not been charged.'],
        });
    }
}
