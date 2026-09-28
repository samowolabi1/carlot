<?php

namespace App\Http\Controllers\Deals;

use App\Domain\Deals\Actions\AnswerCounterOffer;
use App\Domain\Deals\Actions\MakeOffer;
use App\Domain\Deals\Models\Offer;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use App\Http\Requests\Deals\OfferRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's side of offers (designs 09 and 19). */
class OfferController extends Controller
{
    public function create(Request $request, string $car): Response
    {
        $vehicle = DealsPresenter::car($car);
        abort_unless(DealsPresenter::forCar($vehicle, $request->user())['offers'], 404);

        return Inertia::render('Deals/MakeOffer', [
            'car' => MarketplacePresenter::card($vehicle),
            'price' => intdiv((int) $vehicle->price, 100),
            'market' => DealsPresenter::market($vehicle),
        ])->withViewData(['meta' => ['title' => 'Make an offer — '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(OfferRequest $request, string $car, MakeOffer $make): RedirectResponse
    {
        $vehicle = DealsPresenter::car($car);
        $make->run($request->user(), $vehicle, $request->amount(), $request->validated('message'));

        return redirect(route('bookings.index').'#offers')->with('success', 'Offer sent. '.$vehicle->lot->name.' has 48 hours to reply.');
    }

    /** "Accept" or "Accept and reserve" on a counter-offer. */
    public function accept(Request $request, Offer $offer, AnswerCounterOffer $answer): RedirectResponse
    {
        $offer = $answer->run($offer, $request->user(), true);

        if ($request->boolean('reserve')) {
            return to_route('reservations.create', $offer->vehicle->ulid);
        }

        return back()->with('success', 'Deal. '.$offer->money($offer->counter_amount).' is agreed; book a visit to complete it.');
    }

    public function decline(Request $request, Offer $offer, AnswerCounterOffer $answer): RedirectResponse
    {
        $answer->run($offer, $request->user(), false);

        return back()->with('success', 'Counter-offer declined.');
    }
}
