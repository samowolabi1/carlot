<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Location\Actions\StartLocationSession;
use App\Domain\Location\Models\LocationSession;
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

// Live location (TDD M8): the sharer and the other side of that appointment only.
Broadcast::channel('location-session.{ulid}', function (User $user, string $ulid) {
    $session = LocationSession::withoutGlobalScopes()->where('ulid', $ulid)->first();
    $appointment = $session ? Appointment::withoutGlobalScopes()->find($session->appointment_id) : null;

    if ($session === null || $appointment === null) {
        return false;
    }

    return $session->sharer_id === $user->id
        || StartLocationSession::side($user, $appointment) === ($session->sharer_side === 'customer' ? 'lot' : 'customer');
});
