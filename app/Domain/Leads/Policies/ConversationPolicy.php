<?php

namespace App\Domain\Leads\Policies;

use App\Domain\Accounts\Models\User;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;

/** The buyer on the lead, or anyone working at the lot (TDD: private-conversation channel). */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->isCustomer($user, $conversation) || $this->isStaff($user, $conversation);
    }

    public function isCustomer(User $user, Conversation $conversation): bool
    {
        return Lead::withoutGlobalScopes()->whereKey($conversation->lead_id)->value('customer_id') === $user->id;
    }

    public function isStaff(User $user, Conversation $conversation): bool
    {
        $lotId = Lead::withoutGlobalScopes()->whereKey($conversation->lead_id)->value('lot_id');

        return $lotId !== null && ($lot = Lot::find($lotId)) !== null && $user->hasLotRole($lot);
    }
}
