<?php

namespace App\Domain\LotManager\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case DepositPaid = 'deposit_paid';
    case FullyPaid = 'fully_paid';
    case PapersReady = 'papers_ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::DepositPaid => 'Deposit paid',
            self::FullyPaid => 'Fully paid',
            self::PapersReady => 'Papers ready',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Orders that count as open (plan limit) and hold their car. */
    public static function open(): array
    {
        return [self::Draft, self::DepositPaid, self::FullyPaid, self::PapersReady];
    }

    public function rank(): int
    {
        return array_search($this, [self::Draft, self::DepositPaid, self::FullyPaid, self::PapersReady, self::Delivered], true) ?: 0;
    }
}
