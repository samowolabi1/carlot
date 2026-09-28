<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum PaymentMethod: string
{
    use HasOptions;

    case Cash = 'cash';
    case Transfer = 'transfer';
    case Pos = 'pos';
    case Paystack = 'paystack';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Transfer => 'Bank transfer',
            self::Pos => 'POS',
            self::Paystack => 'Paystack',
            self::Other => 'Other',
        };
    }
}
