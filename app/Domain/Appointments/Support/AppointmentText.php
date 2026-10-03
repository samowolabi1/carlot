<?php

namespace App\Domain\Appointments\Support;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\URL;

/** Wording and links shared by booking messages. */
final class AppointmentText
{
    public static function lot(Appointment $appointment): Lot
    {
        return Lot::withTrashed()->findOrFail($appointment->lot_id);
    }

    /** "Test drive · 2018 Toyota Camry SE" */
    public static function what(Appointment $appointment): string
    {
        $vehicle = $appointment->vehicle()->with(['make', 'model'])->first();

        return $appointment->type->label().($vehicle ? ' · '.$vehicle->title() : '');
    }

    /** "Tue 29 Sep, 10:30" in the seller's timezone */
    public static function when(Appointment $appointment, Lot $lot): string
    {
        return $appointment->whenLabel($lot->timezone);
    }

    /** Opens the booking without signing in, so links in WhatsApp and SMS just work. */
    public static function manageUrl(Appointment $appointment): string
    {
        return URL::signedRoute('bookings.show', ['appointment' => $appointment->ulid], $appointment->starts_at->copy()->addDays(30));
    }
}
