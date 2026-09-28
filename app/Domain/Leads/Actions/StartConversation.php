<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;

class StartConversation
{
    public function __construct(private readonly CaptureLead $capture, private readonly SendMessage $send) {}

    /** A buyer's first message to a lot, about a car or the lot in general (design 15). */
    public function run(User $customer, Lot $lot, ?Vehicle $vehicle, ?string $body = null): Conversation
    {
        $lead = $this->capture->run($lot, $customer, LeadSource::Chat, $vehicle);
        $conversation = self::for($lead);

        if (filled($body)) {
            $this->send->run($conversation, $customer, Message::CUSTOMER, (string) $body);
        }

        return $conversation;
    }

    public static function for(Lead $lead): Conversation
    {
        return Conversation::firstOrCreate(['lead_id' => $lead->id]);
    }
}
