<?php

namespace App\Domain\LotManager\Enums;

enum InstalmentStatus: string
{
    case Pending = 'pending';
    case PartPaid = 'part_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Due',
            self::PartPaid => 'Part paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
        };
    }
}
