<?php

namespace App\Http\Controllers\Deals;

use App\Domain\Deals\Actions\AnswerTradeIn;
use App\Domain\Deals\Actions\SubmitTradeIn;
use App\Domain\Deals\Enums\TradeInCondition;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use App\Http\Requests\Deals\TradeInRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Trade in my car" (design 10), the buyer's answer, and the private photos. */
class TradeInController extends Controller
{
    public function create(Request $request, Lot $lot): Response|RedirectResponse
    {
        abort_unless($lot->status === LotStatus::Active, 404);
        if (! $lot->takesTradeIns()) {
            return redirect()->route('lots.show', $lot)->with('error', "{$lot->name} isn't taking trade-ins right now.");
        }
        $car = $this->towards($request->query('car'), $lot);

        return Inertia::render('Deals/TradeIn', [
            'lot' => ['slug' => $lot->slug, 'name' => $lot->name],
            'car' => $car ? MarketplacePresenter::card($car) : null,
            'makes' => Make::query()->with(['models' => fn ($q) => $q->whereNotNull('approved_at')->orderBy('name')])->orderBy('name')->get()
                ->map(fn (Make $m) => ['id' => $m->id, 'name' => $m->name, 'models' => $m->models->map(fn ($x) => ['id' => $x->id, 'name' => $x->name])->values()]),
            'conditions' => TradeInCondition::options(),
            'maxPhotos' => TradeIn::MAX_PHOTOS,
        ])->withViewData(['meta' => ['title' => "Trade in your car — {$lot->name}", 'robots' => 'noindex']]);
    }

    public function store(TradeInRequest $request, Lot $lot, SubmitTradeIn $submit): RedirectResponse
    {
        abort_unless($lot->status === LotStatus::Active, 404);

        $submit->run($lot, $request->user(), $request->safe()->except(['photos', 'car']), array_values($request->file('photos', [])), $this->towards($request->validated('car'), $lot));

        return redirect(route('bookings.index').'#offers')->with('success', "Sent to {$lot->name}. You'll get a price range on WhatsApp.");
    }

    public function answer(Request $request, TradeIn $tradeIn, AnswerTradeIn $answer): RedirectResponse
    {
        $accept = $request->validate(['accept' => ['required', 'boolean']])['accept'];
        $answer->run($tradeIn, $request->user(), (bool) $accept);

        return back()->with('success', $accept ? 'Great. Bring the car when you visit; the seller will confirm the price.' : 'Thanks for letting them know.');
    }

    /** Photos are private: a signed link for the seller's team and the buyer. */
    public function photo(TradeIn $tradeIn, int $index): StreamedResponse
    {
        $path = $tradeIn->photos[$index] ?? abort(404);

        return Storage::disk(TradeIn::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=1800']);
    }

    private function towards(mixed $ulid, Lot $lot): ?Vehicle
    {
        return is_string($ulid) && $ulid !== ''
            ? Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($ulid))->where('vehicles.lot_id', $lot->id)->with(['make', 'model', 'lot', 'cover'])->first()
            : null;
    }
}
