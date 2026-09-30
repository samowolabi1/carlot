<?php

namespace App\Http\Controllers\Deals;

use App\Domain\Deals\Actions\ReservationDeposits;
use App\Domain\Deals\Actions\StartReservation;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Reserve this car" (design 11). The buyer pays the lot's own bank account; LotLink never
 * handles the money. The lot confirms the transfer and the car is held from then.
 */
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
            'payWithin' => Reservation::PAY_WITHIN_HOURS,
            'refundable' => $lot->reservation_refundable,
        ])->withViewData(['meta' => ['title' => 'Reserve — '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(Request $request, string $car, StartReservation $start): RedirectResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', Rule::in(Reservation::HOURS)]]);

        $reservation = $start->run($request->user(), DealsPresenter::car($car), (int) $data['hours']);

        return redirect()->route('reservations.show', $reservation);
    }

    /** How to pay: the lot's bank details, the amount and the reference, then where things stand. */
    public function show(Request $request, Reservation $reservation): Response
    {
        abort_unless($reservation->customer_id === $request->user()->id, 404);

        $lot = Lot::withTrashed()->findOrFail($reservation->lot_id);
        $account = $reservation->status === ReservationStatus::Pending ? LotBankAccount::preferredFor($lot->id) : null;
        $tz = $lot->timezone;

        return Inertia::render('Deals/ReservationPay', [
            'reservation' => [
                ...DealsPresenter::buyerReservation($reservation, $lot),
                'reference' => $reservation->reference,
                'hours' => $reservation->hours,
                'pay_by' => $reservation->pay_by?->copy()->setTimezone($tz)->format('D j M, g:ia'),
                'sent' => $reservation->buyer_paid_at !== null,
                'refund_due' => $reservation->refund_due && $reservation->refunded_at === null,
            ],
            'account' => $account?->present(),
            'lot' => [
                'name' => $lot->name,
                'phone' => $lot->phone,
                'whatsapp' => $lot->whatsapp ? ltrim($lot->whatsapp, '+') : null,
            ],
        ])->withViewData(['meta' => ['title' => 'Reservation', 'robots' => 'noindex']]);
    }

    public function sent(Request $request, Reservation $reservation, ReservationDeposits $deposits): RedirectResponse
    {
        abort_unless($reservation->customer_id === $request->user()->id, 404);
        $deposits->sent($reservation, $request->user());

        return back(); // The page itself now says the lot has been told.
    }
}
