<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

/** What kind of lender it is (and so which licence or identity check applies: CBN, state moneylender licence, or NIN for individuals). */
enum LenderType: string
{
    use HasOptions;

    case CommercialBank = 'commercial_bank';
    case MicrofinanceBank = 'microfinance_bank';
    case FinanceCompany = 'finance_company';
    case MoneyLender = 'money_lender';
    case Individual = 'individual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CommercialBank => 'Commercial bank',
            self::MicrofinanceBank => 'Microfinance bank',
            self::FinanceCompany => 'Finance company',
            self::MoneyLender => 'Licensed money lender',
            self::Individual => 'Individual lender',
            self::Other => 'Other licensed lender',
        };
    }
}
