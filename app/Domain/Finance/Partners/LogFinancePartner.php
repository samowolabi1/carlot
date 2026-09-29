<?php

namespace App\Domain\Finance\Partners;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Support\FinanceCalculator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FINANCE_PARTNER_DRIVER=log: a stand-in lender for Laragon and tests. It pre-approves when the
 * repayment fits the same affordability rule as the budget calculator.
 */
class LogFinancePartner implements FinancePartner
{
    public function code(): string
    {
        return (string) config('lotlink.finance_partner.code', 'demo');
    }

    public function name(): string
    {
        return (string) config('lotlink.finance_partner.name', 'Demo Finance');
    }

    public function submit(FinanceApplication $application): array
    {
        $a = $application->applicant;
        $income = (int) ($a['monthly_income'] ?? 0);
        $commitments = (int) ($a['monthly_commitments'] ?? 0);
        $rate = (float) config('lotlink.finance.interest_rate');
        $monthly = FinanceCalculator::monthlyPayment(intdiv($application->amount, 100), $rate, $application->tenor_months);
        $limit = ($income - $commitments) * (float) config('lotlink.finance.affordability_ratio');
        $fits = $monthly > 0 && $monthly <= $limit;

        Log::info('Finance pre-qualification sent to '.$this->name(), ['application' => $application->ulid, 'amount' => $application->amount]);

        return [
            'reference' => 'DEMO-'.Str::upper(Str::random(8)),
            'status' => $fits ? 'pre_approved' : 'declined',
            'message' => $fits
                ? 'Pre-approved in principle, subject to documents and a credit check.'
                : 'The repayment is more than we can offer on this income. A bigger deposit or a longer term may help.',
            'approved_amount' => $fits ? $application->amount : null,
        ];
    }
}
