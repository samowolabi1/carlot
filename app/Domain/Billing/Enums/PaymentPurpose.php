<?php

namespace App\Domain\Billing\Enums;

enum PaymentPurpose: string
{
    case Subscription = 'subscription'; // first payment for a plan
    case Renewal = 'renewal';           // Paystack charging the saved card each month
    case Spotlight = 'spotlight';
    case Advert = 'advert';             // a homepage or search banner (AdCampaign)
    case Reservation = 'reservation';   // legacy: buyers now pay lots directly
    case Deposit = 'deposit';           // legacy: buyers now pay lots directly

    /** Payments a lot makes to LotLink (the Billing page and invoices): plans, spotlights and adverts. @return list<self> */
    public static function billing(): array
    {
        return [self::Subscription, self::Renewal, self::Spotlight, self::Advert];
    }
}
