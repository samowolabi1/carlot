<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

/** The CBN licence a lender holds. */
enum LenderType: string
{
    use HasOptions;

    case CommercialBank = 'commercial_bank';
    case MicrofinanceBank = 'microfinance_bank';
    case FinanceCompany = 'finance_company';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CommercialBank => 'Commercial bank',
            self::MicrofinanceBank => 'Microfinance bank',
            self::FinanceCompany => 'Finance company',
            self::Other => 'Other licensed lender',
        };
    }
}
