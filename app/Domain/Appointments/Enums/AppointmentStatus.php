<?php

namespace App\Domain\Appointments\Enums;

enum AppointmentStatus: string
{
    case AwaitingDeposit = 'awaiting_deposit'; // holds the slot for 30 minutes while the buyer pays (S9)
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingDeposit => 'Deposit due',
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
