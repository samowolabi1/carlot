<?php

namespace App\Domain\Helpdesk\Enums;

enum TicketCategory: string
{
    case Account = 'account';
    case Billing = 'billing';
    case Listings = 'listings';
    case Bookings = 'bookings';
    case Payments = 'payments';
    case Verification = 'verification';
    case Technical = 'technical';
    case Feature = 'feature';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Account and staff',
            self::Billing => 'Plan and billing',
            self::Listings => 'Cars and listings',
            self::Bookings => 'Bookings and leads',
            self::Payments => 'Deposits and payouts',
            self::Verification => 'Verification',
            self::Technical => 'Something isn\'t working',
            self::Feature => 'Idea or feature request',
            self::Other => 'Something else',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
