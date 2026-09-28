<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Broadcast;

// Private channels (TDD: Realtime channels). Each check is the same rule the pages use.

Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);

Broadcast::channel('lot.{id}', fn (User $user, int $id) => ($lot = Lot::find($id)) !== null && $user->hasLotRole($lot));

Broadcast::channel('conversation.{ulid}', function (User $user, string $ulid) {
    $conversation = Conversation::where('ulid', $ulid)->first();
    $lead = $conversation ? Lead::withoutGlobalScopes()->find($conversation->lead_id) : null;

    if ($lead === null) {
        return false;
    }

    // Returning the name lets the other side show "typing" by name via whispers.
    return $lead->customer_id === $user->id || $user->hasLotRole(Lot::findOrFail($lead->lot_id))
        ? ['name' => $user->name ? explode(' ', $user->name)[0] : 'Someone']
        : false;
});
