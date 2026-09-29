<?php

namespace App\Domain\Deals\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';     // requested; the buyer is transferring the deposit to the lot
    case Active = 'active';       // the lot confirmed the deposit; the car is held
    case Converted = 'converted'; // became a sale; the deposit counts towards the price
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';       // not confirmed in time, declined, or the car went to someone else

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the lot to confirm your transfer',
            self::Active => 'Reserved',
            self::Converted => 'Bought',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Not completed',
        };
    }
}
