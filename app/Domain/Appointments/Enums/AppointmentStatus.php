<?php

namespace App\Domain\Appointments\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for lot',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::NoShow => 'No-show',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Statuses that hold a place in a slot. */
    public static function active(): array
    {
        return [self::Pending, self::Confirmed];
    }
}
