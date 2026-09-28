<?php

namespace App\Domain\Deals\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';     // checkout started, not paid yet
    case Active = 'active';       // paid; the car is held
    case Converted = 'converted'; // became a sale; the deposit counts towards the price
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Failed = 'failed';       // payment failed, or the car was gone by the time it arrived

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for payment',
            self::Active => 'Reserved',
            self::Converted => 'Bought',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Not completed',
        };
    }
}
