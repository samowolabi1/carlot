<?php

namespace App\Domain\Appointments\Support;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;

/** An .ics file so buyers can add a visit to Google or Apple Calendar (TDD M7). */
class IcsCalendar
{
    public static function for(Appointment $appointment, Lot $lot, string $title): string
    {
        $fmt = fn ($t) => $t->copy()->utc()->format('Ymd\THis\Z');
        $location = implode(', ', array_filter([$lot->name, $lot->address, $lot->city, $lot->state]));
        $description = trim(implode('\n', array_filter([
            $lot->directionsUrl() ? 'Directions: '.$lot->directionsUrl() : null,
            $lot->phone ? 'Lot phone: '.$lot->phone : null,
            'Manage your booking: '.route('bookings.show', $appointment),
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//LotLink//Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$appointment->ulid.'@lotlink',
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($appointment->starts_at),
            'DTEND:'.$fmt($appointment->ends_at),
            'SUMMARY:'.self::escape($title),
            'LOCATION:'.self::escape($location),
            'DESCRIPTION:'.self::escape($description, keepNewlines: true),
            $lot->hasLocation() ? sprintf('GEO:%F;%F', $lot->latitude, $lot->longitude) : null,
            'STATUS:'.($appointment->isActive() ? 'CONFIRMED' : 'CANCELLED'),
            'BEGIN:VALARM',
            'TRIGGER:-PT2H',
            'ACTION:DISPLAY',
            'DESCRIPTION:'.self::escape($title),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), array_filter($lines)))."\r\n";
    }

    private static function escape(string $value, bool $keepNewlines = false): string
    {
        $value = str_replace(['\\', ';', ','], ['\\\\', '\;', '\,'], $value);

        return $keepNewlines ? str_replace('\\\\n', '\n', $value) : str_replace(["\r", "\n"], ' ', $value);
    }

    /** RFC 5545: lines longer than 75 octets continue on the next line after a space. */
    private static function fold(string $line): string
    {
        $out = '';

        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--; // don't split a UTF-8 character
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
