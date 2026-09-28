<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum FollowUpType: string
{
    use HasOptions;

    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Visit = 'visit';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Visit => 'Visit',
        };
    }
}
