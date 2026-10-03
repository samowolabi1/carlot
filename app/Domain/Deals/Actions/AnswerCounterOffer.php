<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AnswerCounterOffer
{
    public function __construct(private readonly DealTimeline $timeline) {}

    /** The buyer takes or turns down the seller's counter-offer (design 19). */
    public function run(Offer $offer, User $customer, bool $accept): Offer
    {
        $offer = DB::transaction(function () use ($offer, $customer, $accept): Offer {
            $locked = Offer::withoutGlobalScopes()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->customer_id !== $customer->id) {
                abort(403);
            }

            if ($locked->status !== OfferStatus::Countered || $locked->expires_at->isPast()) {
                throw ValidationException::withMessages(['offer' => 'This counter-offer has closed.']);
            }

            $locked->forceFill(['status' => $accept ? OfferStatus::Accepted : OfferStatus::Declined, 'closed_at' => now()])->save();
            AuditLog::record($accept ? 'offer.counter_accepted' : 'offer.counter_declined', $locked, ['counter' => $locked->counter_amount], $customer, $locked->lot_id);

            return $locked;
        });

        $lot = Lot::findOrFail($offer->lot_id);
        $who = Name::short($customer->name);
        $counter = $offer->money($offer->counter_amount);

        $this->timeline->post($offer->lead, $accept ? "{$who} accepted the counter-offer of {$counter}" : "{$who} declined the counter-offer of {$counter}", $accept ? LeadStage::Negotiating : null);

        Notification::send($lot->members()->get(), new DealAlert(
            'offer',
            ($accept ? "{$who} accepted your counter-offer of {$counter}" : "{$who} declined your counter-offer of {$counter}").' for the '.$offer->vehicle->title().'.',
            DealLinks::lot($lot, 'offers'),
        ));

        return $offer;
    }
}
