<?php

namespace App\Domain\Deals\Support;

use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Actions\StartConversation;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;

/**
 * Offers, trade-ins and reservations show in the lead's chat as system lines, so the lot
 * and the buyer see one history (design D6), and they move the lead along the funnel.
 */
class DealTimeline
{
    public function __construct(private readonly SendMessage $send) {}

    public function post(?Lead $lead, string $text, ?LeadStage $advanceTo = null): void
    {
        if ($lead === null) {
            return;
        }

        if ($advanceTo !== null && $lead->isOpen() && $lead->stage->rank() < $advanceTo->rank()) {
            $lead->forceFill(['stage' => $advanceTo, 'last_activity_at' => now()])->save();
        }

        $this->send->run(StartConversation::for($lead), null, Message::SYSTEM, $text);
    }
}
