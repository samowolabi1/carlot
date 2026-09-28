<?php

namespace App\Domain\Billing\Enums;

enum PaymentPurpose: string
{
    case Subscription = 'subscription'; // first payment for a plan
    case Renewal = 'renewal';           // Paystack charging the saved card each month
    case Spotlight = 'spotlight';
}
