<?php

namespace App\Domain\Finance\Lenders;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Support\FinanceCalculator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A stand-in lender for Laragon and demos (integration "demo"). It pre-approves at once when the repayment
 * fits the same affordability rule as the budget calculator, and declines otherwise.
 */
class DemoConnection implements LenderConnection
{
    public function submit(FinanceApplication $application): ?array
    {
        $a = $application->applicant;
        $income = (int) ($a['monthly_income'] ?? 0);
        $commitments = (int) ($a['monthly_commitments'] ?? 0);
        $rate = $application->lender ? $application->lender->rate_bp / 100 : (float) config('lotlink.finance.interest_rate');
        $monthly = FinanceCalculator::monthlyPayment(intdiv($application->amount, 100), $rate, $application->tenor_months);
        $limit = ($income - $commitments) * (float) config('lotlink.finance.affordability_ratio');
        $fits = $monthly > 0 && $monthly <= $limit;

        Log::info('Car loan application sent to the demo lender', ['application' => $application->ulid, 'amount' => $application->amount]);

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
