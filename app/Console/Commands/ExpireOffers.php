<?php

namespace App\Console\Commands;

use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Lots\Models\Lot;
use Illuminate\Console\Command;

/** Hourly (TDD: offers:expire): offers and counters left for 48 hours close. */
class ExpireOffers extends Command
{
    protected $signature = 'offers:expire';

    protected $description = 'Expire offers and counter-offers nobody answered within 48 hours';

    public function handle(DealTimeline $timeline): int
    {
        $count = 0;

        Offer::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model', 'customer', 'lead'])
            ->whereIn('status', OfferStatus::open())->where('expires_at', '<=', now())
            ->each(function (Offer $offer) use ($timeline, &$count): void {
                $wasCounter = $offer->status === OfferStatus::Countered;
                $offer->forceFill(['status' => OfferStatus::Expired, 'closed_at' => now()])->save();
                $lot = Lot::withTrashed()->findOrFail($offer->lot_id);

                $timeline->post($offer->lead, $wasCounter ? 'Counter-offer expired' : 'Offer expired without a reply');
                $offer->customer->notify(new DealUpdate(
                    'offer',
                    $offer->vehicle->title(),
                    $lot->name,
                    $wasCounter ? 'Their counter-offer has expired. You can make a new offer.' : "Your offer of {$offer->money()} expired without a reply. You can make a new offer or message the seller.",
                    DealLinks::buyer(),
                ));
                $count++;
            });

        $this->info("Expired {$count} offers.");

        return self::SUCCESS;
    }
}
