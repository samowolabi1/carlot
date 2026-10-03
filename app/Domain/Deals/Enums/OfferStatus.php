<?php

namespace App\Domain\Deals\Enums;

enum OfferStatus: string
{
    case Pending = 'pending';     // waiting for the seller
    case Countered = 'countered'; // waiting for the buyer
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn'; // replaced by the buyer's newer offer

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for the seller',
            self::Countered => 'Countered',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Expired => 'Expired',
            self::Withdrawn => 'Replaced',
        };
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Countered];
    }
}
