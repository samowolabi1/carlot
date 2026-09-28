<?php

namespace App\Domain\Billing\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Success => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }
}
