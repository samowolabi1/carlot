<?php

namespace App\Domain\Location\Actions;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Location\Events\LocationUpdated;
use App\Domain\Location\Models\LocationSession;

class EndLocationSession
{
    /** Stops sharing and clears the stored point: on expiry, by hand, or when the visit ends. */
    public function run(LocationSession $session, string $reason = 'stopped'): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        $session->forceFill([
            'ended_at' => now(),
            'last_latitude' => null,
            'last_longitude' => null,
            'accuracy_m' => null,
        ])->save();

        broadcast(new LocationUpdated($session));
    }

    /** The visit completed, was a no-show or was cancelled. */
    public function forAppointment(Appointment $appointment): void
    {
        LocationSession::withoutGlobalScopes()->whereNull('ended_at')->where('appointment_id', $appointment->id)->get()
            ->each(fn (LocationSession $s) => $this->run($s, 'visit ended'));
    }
}
