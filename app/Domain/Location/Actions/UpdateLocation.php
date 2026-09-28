<?php

namespace App\Domain\Location\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Location\Events\LocationUpdated;
use App\Domain\Location\Models\LocationSession;
use Illuminate\Validation\ValidationException;

class UpdateLocation
{
    /** The sharer's browser sends a point every ~10 s; only the latest is kept (TDD M8). */
    public function run(LocationSession $session, User $user, float $lat, float $lng, ?int $accuracy): LocationSession
    {
        abort_unless($session->sharer_id === $user->id, 403);

        if (! $session->isLive()) {
            throw ValidationException::withMessages(['session' => 'Sharing has stopped.']);
        }

        $session->forceFill([
            'last_latitude' => round($lat, 7),
            'last_longitude' => round($lng, 7),
            'accuracy_m' => $accuracy !== null ? min(65535, max(0, $accuracy)) : null,
            'last_seen_at' => now(),
        ])->save();

        broadcast(new LocationUpdated($session))->toOthers();

        return $session;
    }
}
