<?php

namespace App\Domain\Location\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Location\Notifications\LocationShared;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class StartLocationSession
{
    /** How long before a visit sharing can start, and how long after its end it can run. */
    public const BEFORE_HOURS = 3;

    public const AFTER_HOURS = 1;

    public function __construct(private readonly EndLocationSession $end) {}

    /** Which side of the booking a user is on, or null if they aren't part of it. */
    public static function side(User $user, Appointment $appointment): ?string
    {
        if ($appointment->customer_id === $user->id) {
            return LocationSession::CUSTOMER;
        }

        return $user->hasLotRole(Lot::withTrashed()->findOrFail($appointment->lot_id)) ? LocationSession::LOT : null;
    }

    public static function canShare(Appointment $appointment): bool
    {
        return $appointment->isActive()
            && $appointment->starts_at->lte(now()->addHours(self::BEFORE_HOURS))
            && $appointment->ends_at->gte(now()->subHours(self::AFTER_HOURS));
    }

    /**
     * Either party starts sharing for 15, 30, 60 or 120 minutes (TDD M8). Starting again
     * replaces the sharer's earlier session for the same booking.
     */
    public function run(Appointment $appointment, User $user, int $minutes): LocationSession
    {
        $side = self::side($user, $appointment) ?? abort(403);

        if (! in_array($minutes, LocationSession::MINUTES, true)) {
            throw ValidationException::withMessages(['minutes' => 'Share for 15, 30, 60 or 120 minutes.']);
        }

        if (! self::canShare($appointment)) {
            throw ValidationException::withMessages(['minutes' => 'You can share your location from '.self::BEFORE_HOURS.' hours before the visit until it ends.']);
        }

        $previous = LocationSession::withoutGlobalScopes()->live()->where('appointment_id', $appointment->id)->where('sharer_id', $user->id)->get();
        $previous->each(fn (LocationSession $s) => $this->end->run($s, 'replaced'));

        $session = DB::transaction(fn () => LocationSession::withoutGlobalScopes()->create([
            'lot_id' => $appointment->lot_id,
            'appointment_id' => $appointment->id,
            'sharer_id' => $user->id,
            'sharer_side' => $side,
            'started_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]));

        // Tell the other side where to follow along, unless they already knew from a replaced session.
        if ($previous->isEmpty()) {
            $lot = Lot::withTrashed()->findOrFail($appointment->lot_id);
            $viewers = $side === LocationSession::CUSTOMER ? $lot->members()->get() : collect([$appointment->customer]);
            Notification::send($viewers, new LocationShared($session));
        }

        return $session;
    }
}
