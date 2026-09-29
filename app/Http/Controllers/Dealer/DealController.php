<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Deals\Actions\ActivateReservation;
use App\Domain\Deals\Actions\EndReservation;
use App\Domain\Deals\Actions\ReservationDeposits;
use App\Domain\Deals\Actions\RespondToOffer;
use App\Domain\Deals\Actions\ValueTradeIn;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Actions\StartConversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use App\Domain\Support\Name;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Offers and trade-ins (design D7): reply to offers, value trade-ins, manage reservations. */
class DealController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $tab = in_array($request->query('tab'), ['offers', 'trade-ins', 'reservations'], true) ? $request->query('tab') : 'offers';
        $tz = $lot->timezone;

        $offers = Offer::query()->whereIn('status', [...OfferStatus::open(), OfferStatus::Accepted])
            ->where(fn ($q) => $q->whereIn('status', OfferStatus::open())->orWhere('closed_at', '>', now()->subDays(7)))
            ->with(['vehicle.make', 'vehicle.model', 'vehicle.cover', 'customer', 'lead'])
            ->orderByRaw("case status when 'pending' then 0 when 'countered' then 1 else 2 end")->orderBy('expires_at')
            ->get();
        // How many leads each car has: an old car with one lead is worth a softer answer.
        $leadCounts = Lead::query()->whereIn('vehicle_id', $offers->pluck('vehicle_id'))->selectRaw('vehicle_id, count(*) as n')->groupBy('vehicle_id')->pluck('n', 'vehicle_id');

        $tradeIns = TradeIn::query()->whereIn('status', [TradeInStatus::Submitted, TradeInStatus::Valued, TradeInStatus::Accepted])
            ->where(fn ($q) => $q->where('status', TradeInStatus::Submitted)->orWhere('valued_at', '>', now()->subDays(30)))
            ->with(['make', 'model', 'customer', 'vehicle.make', 'vehicle.model', 'lead'])
            ->orderByRaw("case status when 'submitted' then 0 else 1 end")->latest('id')->get();

        // Requests waiting for the buyer's transfer, cars on hold, and deposits the lot still owes back.
        $reservations = Reservation::query()
            ->where(fn ($q) => $q->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Active])
                ->orWhere(fn ($q) => $q->where('refund_due', true)->whereNull('refunded_at')))
            ->with(['vehicle.make', 'vehicle.model', 'vehicle.cover', 'customer'])
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")->orderBy('expires_at')->orderBy('pay_by')
            ->get();

        return Inertia::render('Dealer/Deals', [
            'tab' => $tab,
            'takesOffers' => $lot->takesOffers(),
            'planAllows' => ['offers' => $lot->planAllows('offers'), 'deposits' => $lot->planAllows('deposits')],
            'reservationsOn' => $lot->reservationDeposit() !== null,
            'hasBank' => $lot->bankAccounts()->exists(),
            'offers' => $offers->map(function (Offer $o) use ($leadCounts, $lot) {
                $v = $o->vehicle;
                $days = $v->daysListed();

                return [
                    'ulid' => $o->ulid,
                    'status' => $o->status->value,
                    'car' => $v->title(),
                    'image' => MarketplacePresenter::image($v->cover),
                    'buyer' => Name::short($o->customer->name),
                    'left' => DealsPresenter::left($o->expires_at),
                    'asking' => $v->formattedPrice(),
                    'amount' => $o->money(),
                    'amount_value' => intdiv($o->amount, 100),
                    'asking_value' => $v->price !== null ? intdiv($v->price, 100) : null,
                    'off' => Offer::discount($o->amount, $v->price),
                    'counter' => $o->counter_amount ? $o->money($o->counter_amount) : null,
                    'message' => $o->message,
                    'lead_url' => $o->lead ? route('dealer.leads.show', [$lot, $o->lead]) : null,
                    // Design D7: "The Pilot has been listed 92 days with no other leads."
                    'hint' => $o->status === OfferStatus::Pending && $v->isAgeing() && ($leadCounts[$v->id] ?? 0) <= 1
                        ? "The {$v->model?->name} has been listed {$days} days with no other leads. Accepting or countering could clear it this week."
                        : null,
                ];
            }),
            'tradeIns' => $tradeIns->map(fn (TradeIn $t) => [
                'ulid' => $t->ulid,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'title' => $t->title().' · '.number_format($t->mileage_km).' km',
                'condition' => $t->condition->short(),
                'buyer' => Name::short($t->customer->name),
                'towards' => $t->vehicle?->title(),
                'notes' => $t->notes,
                'photos' => $t->photoUrls(),
                'estimate' => $t->estimate(),
                'low' => $t->estimate_low !== null ? intdiv($t->estimate_low, 100) : null,
                'high' => $t->estimate_high !== null ? intdiv($t->estimate_high, 100) : null,
                'lead_url' => $t->lead ? route('dealer.leads.show', [$lot, $t->lead]) : null,
            ]),
            'reservations' => $reservations->map(fn (Reservation $r) => [
                'ulid' => $r->ulid,
                'status' => $r->refund_due && ! in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Active], true) ? 'refund' : $r->status->value,
                'reference' => $r->reference,
                'hours' => $r->hours,
                'buyer_sent' => $r->buyer_paid_at?->copy()->setTimezone($tz)->format('D j M, g:ia'),
                'pay_by' => $r->pay_by?->copy()->setTimezone($tz)->format('D j M, g:ia'),
                'end_reason' => $r->end_reason,
                'car' => $r->vehicle->title(),
                'image' => MarketplacePresenter::image($r->vehicle->cover),
                'buyer' => Name::short($r->customer->name),
                'deposit' => $r->money(),
                'price' => $r->money($r->price),
                'until' => $r->expires_at?->copy()->setTimezone($tz)->format('D j M, g:ia'),
                'left' => DealsPresenter::left($r->expires_at),
                'order_url' => route('dealer.manager.orders.create', [$lot, 'vehicle' => $r->vehicle->ulid]),
            ]),
            'counts' => [
                'offers' => $offers->where('status', OfferStatus::Pending)->count(),
                'tradeIns' => $tradeIns->where('status', TradeInStatus::Submitted)->count(),
                'reservations' => $reservations->where('status', ReservationStatus::Pending)->count(),
            ],
        ]);
    }

    public function respond(Request $request, Lot $lot, Offer $offer, RespondToOffer $respond): RedirectResponse
    {
        $request->merge(['counter_amount' => preg_replace('/[^\d]/', '', (string) $request->input('counter_amount'))]);
        $data = $request->validate([
            'action' => ['required', Rule::in(RespondToOffer::ACTIONS)],
            'counter_amount' => ['required_if:action,counter', 'nullable', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $offer = $respond->run($offer, $request->user(), $data['action'], filled($data['counter_amount'] ?? null) ? Money::fromMajor((int) $data['counter_amount']) : null, $data['message'] ?? null);

        return back()->with('success', match ($offer->status) {
            OfferStatus::Accepted => 'Offer accepted. The buyer has been told.',
            OfferStatus::Countered => 'Counter-offer sent. The buyer has 48 hours to answer.',
            default => 'Offer declined.',
        });
    }

    public function value(Request $request, Lot $lot, TradeIn $tradeIn, ValueTradeIn $value): RedirectResponse
    {
        foreach (['estimate_low', 'estimate_high'] as $key) {
            $request->merge([$key => preg_replace('/[^\d]/', '', (string) $request->input($key))]);
        }
        $data = $request->validate([
            'estimate_low' => ['required', 'integer', 'min:1'],
            'estimate_high' => ['required', 'integer', 'gte:estimate_low'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $value->run($tradeIn, $request->user(), Money::fromMajor((int) $data['estimate_low']), Money::fromMajor((int) $data['estimate_high']), $data['note'] ?? null);

        return back()->with('success', 'Valuation sent to the buyer.');
    }

    /** Design D7: "Ask for more photos" posts into the buyer's chat. */
    public function askForPhotos(Request $request, Lot $lot, TradeIn $tradeIn, SendMessage $send): RedirectResponse
    {
        $lead = $tradeIn->lead ?? abort(404);
        $send->run(StartConversation::for($lead), $request->user(), Message::LOT, "Thanks for sending your {$tradeIn->title()}. Could you add a few more photos (engine bay, dashboard with the engine running, and any damage) so we can give you a firmer price?");

        return back()->with('success', 'Asked the buyer for more photos in the chat.');
    }

    public function cancelReservation(Request $request, Lot $lot, Reservation $reservation, EndReservation $end): RedirectResponse
    {
        abort_unless($request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:120']])['reason'];

        $end->run($reservation, ReservationStatus::Cancelled, $reason, $request->user());

        return back()->with('success', 'Reservation cancelled and the car is back on sale. Refund the deposit from your account, then mark it refunded here.');
    }

    /** The buyer's transfer reached the lot's account: hold the car. Owners and managers. */
    public function confirmReservation(Request $request, Lot $lot, Reservation $reservation, ActivateReservation $activate): RedirectResponse
    {
        abort_unless($request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager), 403);
        $activate->run($reservation, $request->user());

        return back()->with('success', 'Deposit confirmed. The car is reserved and the buyer has been told.');
    }

    public function declineReservation(Request $request, Lot $lot, Reservation $reservation, ReservationDeposits $deposits): RedirectResponse
    {
        abort_unless($request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:120']])['reason'];
        $deposits->decline($reservation, $request->user(), $reason);

        return back()->with('success', 'Request declined. The buyer has been told.');
    }

    public function refundedReservation(Request $request, Lot $lot, Reservation $reservation, ReservationDeposits $deposits): RedirectResponse
    {
        abort_unless($request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager), 403);
        $deposits->refunded($reservation, $request->user());

        return back()->with('success', 'Marked as refunded. The buyer has been told.');
    }
}
