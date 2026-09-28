<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RespondToOffer
{
    public const ACTIONS = ['accept', 'decline', 'counter'];

    public function __construct(private readonly DealTimeline $timeline) {}

    /** The lot accepts, declines or counters a waiting offer (TDD M12). */
    public function run(Offer $offer, User $staff, string $action, ?int $counter = null, ?string $message = null): Offer
    {
        $offer = DB::transaction(function () use ($offer, $staff, $action, $counter, $message): Offer {
            $locked = Offer::withoutGlobalScopes()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->status !== OfferStatus::Pending || $locked->expires_at->isPast()) {
                throw ValidationException::withMessages(['offer' => 'This offer is no longer waiting for a reply.']);
            }

            $price = (int) $locked->vehicle->price;
            $before = $locked->status->value;

            match ($action) {
                'accept' => $locked->forceFill(['status' => OfferStatus::Accepted, 'closed_at' => now()]),
                'decline' => $locked->forceFill(['status' => OfferStatus::Declined, 'closed_at' => now()]),
                'counter' => match (true) {
                    $counter === null || $counter <= $locked->amount => throw ValidationException::withMessages(['counter_amount' => 'A counter-offer must be above '.$locked->money().'.']),
                    $price > 0 && $counter > $price => throw ValidationException::withMessages(['counter_amount' => 'A counter-offer can\'t be above the asking price.']),
                    default => $locked->forceFill([
                        'status' => OfferStatus::Countered,
                        'counter_amount' => $counter,
                        'counter_message' => filled($message) ? trim((string) $message) : null,
                        'expires_at' => now()->addHours(Offer::HOURS),
                    ]),
                },
                default => throw ValidationException::withMessages(['action' => 'Unknown action.']),
            };

            $locked->forceFill(['responded_by' => $staff->id, 'responded_at' => now()])->save();
            AuditLog::record("offer.{$action}", $locked, ['before' => $before, 'amount' => $locked->amount, 'counter' => $locked->counter_amount], $staff, $locked->lot_id);

            return $locked;
        });

        $lot = Lot::findOrFail($offer->lot_id);
        $car = $offer->vehicle->title();

        [$line, $update] = match ($offer->status) {
            OfferStatus::Accepted => ["{$lot->name} accepted the offer of {$offer->money()}", "They accepted your offer of {$offer->money()}.".($lot->reservationDeposit() ? ' Reserve it with a deposit before someone else does.' : ' Book a visit to complete the deal.')],
            OfferStatus::Countered => ["{$lot->name} countered with ".$offer->money($offer->counter_amount), 'You offered '.$offer->money().'. They countered with '.$offer->money($offer->counter_amount).'. The counter lasts 48 hours.'],
            default => ["{$lot->name} declined the offer of {$offer->money()}", "They declined your offer of {$offer->money()}. You can make another offer."],
        };

        $this->timeline->post($offer->lead, $line, $offer->status === OfferStatus::Declined ? null : LeadStage::Negotiating);
        $offer->customer->notify(new DealUpdate('offer', $car, $lot->name, $update, DealLinks::buyer()));

        return $offer;
    }
}
