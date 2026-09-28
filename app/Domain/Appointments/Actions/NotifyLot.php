<?php

namespace App\Domain\Appointments\Actions;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Notifications\LotBookingAlert;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Notification;

/** Sends a booking alert to the lot's WhatsApp number (its phone, else the owner's). */
class NotifyLot
{
    public function run(Appointment $appointment, string $event, bool $ownerOnly = false): void
    {
        $lot = Lot::withTrashed()->with('owner')->findOrFail($appointment->lot_id);
        $to = $ownerOnly ? $lot->owner?->phone : ($lot->whatsapp ?? $lot->phone ?? $lot->owner?->phone);

        if ($to) {
            Notification::route('phone', $to)->notify(new LotBookingAlert($appointment, $event));
        }
    }
}
