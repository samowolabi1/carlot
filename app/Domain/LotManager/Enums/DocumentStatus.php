<?php

namespace App\Domain\LotManager\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case HandedOver = 'handed_over';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Received => 'Received',
            self::HandedOver => 'Handed over',
        };
    }
}
