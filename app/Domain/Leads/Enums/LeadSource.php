<?php

namespace App\Domain\Leads\Enums;

use App\Domain\Support\HasOptions;

enum LeadSource: string
{
    use HasOptions;

    case Chat = 'chat';
    case WhatsApp = 'whatsapp';
    case Booking = 'booking';
    case Call = 'call';
    case Offer = 'offer';
    case TradeIn = 'trade_in';
    case Reservation = 'reservation';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::WhatsApp => 'WhatsApp',
            self::Booking => 'Booking',
            self::Call => 'Call',
            self::Offer => 'Offer',
            self::TradeIn => 'Trade-in',
            self::Reservation => 'Reservation',
            self::Finance => 'Car loan',
        };
    }
}
