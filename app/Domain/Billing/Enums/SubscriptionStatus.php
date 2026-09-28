<?php

namespace App\Domain\Billing\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::PastDue => 'Payment overdue',
            self::Cancelled => 'Cancelled',
        };
    }
}
