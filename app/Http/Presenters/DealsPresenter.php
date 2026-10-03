<?php

namespace App\Http\Presenters;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Money;
use Carbon\CarbonInterface;

/** Offers, trade-ins and reservations as the buyer and the seller see them (designs 09–11, 19, D7). */
final class DealsPresenter
{
    /** A marketplace car for the offer and reserve pages, or 404. */
    public static function car(string $ulid): Vehicle
    {
        return Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($ulid))->with(['make', 'model', 'lot', 'cover'])->firstOrFail();
    }

    /** What the car page offers this buyer (design 06: Offer, Reserve with deposit, Trade in). @return array<string, mixed> */
    public static function forCar(Vehicle $vehicle, ?User $user): array
    {
        $lot = $vehicle->lot;
        $available = $vehicle->status === VehicleStatus::Available;
        $mine = $user ? Offer::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $user->id)
            ->whereIn('status', [...OfferStatus::open(), OfferStatus::Accepted])->latest('id')->first() : null;
        $reservation = $user ? Reservation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $user->id)
            ->where('status', ReservationStatus::Active)->first() : null;
        $request = $user && $reservation === null ? Reservation::withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->where('customer_id', $user->id)
            ->where('status', ReservationStatus::Pending)->first() : null;

        return [
            'offers' => $available && $vehicle->negotiable && $vehicle->price > 0 && $lot->takesOffers(),
            // The owner's switches (Settings → Offers and deals).
            'trade_ins' => $lot->takesTradeIns(),
            'finance' => $lot->takesFinance() && $vehicle->price > 0,
            'reserve' => $available && $lot->reservationDeposit() !== null ? Money::format($lot->reservationDeposit(), $vehicle->currency) : null,
            'my_offer' => $mine ? [
                'status' => $mine->status->value,
                'text' => match ($mine->status) {
                    OfferStatus::Pending => 'You offered '.$mine->money().'. Waiting for '.$lot->name.'.',
                    OfferStatus::Countered => $lot->name.' countered with '.$mine->money($mine->counter_amount).'.',
                    default => 'Your offer of '.$mine->money((int) $mine->agreedAmount()).' was accepted.',
                },
            ] : null,
            'reserved_until' => $reservation?->expires_at?->copy()->setTimezone($lot->timezone)->format('D j M, g:ia'),
            // A request waiting for the buyer's transfer: back to the seller's bank details.
            'reservation_request' => $request ? route('reservations.show', $request) : null,
        ];
    }

    /**
     * Asking prices of similar cars on CarYard (same make and model, two years either side):
     * the "Similar cars" bar on the offer sheet (design 09). Needs three to mean anything.
     *
     * @return array{low: int, median: int, high: int, count: int}|null whole naira
     */
    public static function market(Vehicle $vehicle): ?array
    {
        if ($vehicle->vehicle_model_id === null) {
            return null;
        }

        $prices = Vehicle::query()->marketplace()
            ->where('vehicles.vehicle_model_id', $vehicle->vehicle_model_id)
            ->when($vehicle->year, fn ($q) => $q->whereBetween('vehicles.year', [$vehicle->year - 2, $vehicle->year + 2]))
            ->whereNotNull('vehicles.price')
            ->pluck('vehicles.price')->map(fn ($p) => intdiv((int) $p, 100))->sort()->values();

        if ($prices->count() < 3) {
            return null;
        }

        return ['low' => (int) $prices->first(), 'median' => (int) $prices->median(), 'high' => (int) $prices->last(), 'count' => $prices->count()];
    }

    /** "41 h left", "25 min left". */
    public static function left(?CarbonInterface $until): ?string
    {
        if ($until === null || $until->isPast()) {
            return null;
        }

        $minutes = (int) now()->diffInMinutes($until);

        return $minutes >= 60 ? intdiv($minutes, 60).' h left' : max(1, $minutes).' min left';
    }

    /** @return array<string, mixed> The buyer's offer card (design 19). */
    public static function buyerOffer(Offer $o, Lot $lot): array
    {
        $vehicle = $o->vehicle;

        return [
            'ulid' => $o->ulid,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'car' => $vehicle->title(),
            'car_url' => $vehicle->publicPath(),
            'car_ulid' => $vehicle->ulid,
            'lot' => $lot->name,
            'amount' => $o->money(),
            'counter' => $o->counter_amount ? $o->money($o->counter_amount) : null,
            'counter_message' => $o->counter_message,
            'agreed' => $o->agreedAmount() ? $o->money((int) $o->agreedAmount()) : null,
            'left' => $o->isOpen() ? self::left($o->expires_at) : null,
            'can_reserve' => $o->status === OfferStatus::Accepted && $vehicle->status === VehicleStatus::Available && $lot->reservationDeposit() !== null,
            'book_url' => route('bookings.create', ['lot' => $lot->slug, 'car' => $vehicle->ulid]),
        ];
    }

    /** @return array<string, mixed> */
    public static function buyerReservation(Reservation $r, Lot $lot): array
    {
        return [
            'ulid' => $r->ulid,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'car' => $r->vehicle->title(),
            'car_url' => $r->vehicle->publicPath(),
            'lot' => $lot->name,
            'deposit' => $r->money(),
            'price' => $r->money($r->price),
            'until' => $r->expires_at?->copy()->setTimezone($lot->timezone)->format('D j M, g:ia'),
            'left' => $r->status === ReservationStatus::Active ? self::left($r->expires_at) : null,
            'end_reason' => $r->end_reason,
            'refunded' => $r->payment?->refunded_at !== null || $r->refunded_at !== null,
            'pay_url' => $r->status === ReservationStatus::Pending ? route('reservations.show', $r) : null,
            'book_url' => route('bookings.create', ['lot' => $lot->slug, 'car' => $r->vehicle->ulid]),
        ];
    }

    /** @return array<string, mixed> */
    public static function buyerTradeIn(TradeIn $t, Lot $lot): array
    {
        return [
            'ulid' => $t->ulid,
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'title' => $t->title(),
            'lot' => $lot->name,
            'towards' => $t->vehicle?->title(),
            'estimate' => $t->estimate(),
            'note' => $t->valuation_note,
            'photos' => count($t->photos ?? []),
            'book_url' => route('bookings.create', ['lot' => $lot->slug, 'type' => 'trade_in']),
            'open' => in_array($t->status, [TradeInStatus::Submitted, TradeInStatus::Valued], true),
        ];
    }
}
