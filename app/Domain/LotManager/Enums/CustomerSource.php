<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum CustomerSource: string
{
    use HasOptions;

    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case Banner = 'banner';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case WhatsApp = 'whatsapp';
    case Marketplace = 'marketplace';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walked in',
            self::Referral => 'Referral',
            self::Banner => 'Roadside banner',
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::WhatsApp => 'WhatsApp',
            self::Marketplace => 'CarYard',
            self::Other => 'Other',
        };
    }
}
