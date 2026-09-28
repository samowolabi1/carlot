<?php

namespace App\Domain\Billing\Enums;

enum PaymentPurpose: string
{
    case Subscription = 'subscription'; // first payment for a plan
    case Renewal = 'renewal';           // Paystack charging the saved card each month
    case Spotlight = 'spotlight';
    case Reservation = 'reservation';   // a buyer holding a car (S9)
    case Deposit = 'deposit';           // a buyer's refundable test-drive deposit (S9)

    /** Payments a lot makes to LotLink (the Billing page and invoices), not buyers' deposits. @return list<self> */
    public static function billing(): array
    {
        return [self::Subscription, self::Renewal, self::Spotlight];
    }
}
