<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\Budget;
use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Support\Money;

class SaveBudget
{
    /**
     * Saves the buyer's budget. The maximum price is worked out here, not taken from the
     * browser, so the "Within budget" tags always match the formula.
     *
     * @param  array{monthly_income: int, monthly_commitments?: ?int, deposit?: ?int, tenor_months: int, interest_rate: float}  $data  whole naira
     */
    public function run(User $user, array $data): Budget
    {
        $commitments = (int) ($data['monthly_commitments'] ?? 0);
        $deposit = (int) ($data['deposit'] ?? 0);
        $result = FinanceCalculator::affordability($data['monthly_income'], $commitments, $deposit, $data['tenor_months'], $data['interest_rate']);

        return Budget::updateOrCreate(['user_id' => $user->id], [
            'monthly_income' => Money::fromMajor($data['monthly_income']),
            'monthly_commitments' => Money::fromMajor($commitments),
            'deposit' => Money::fromMajor($deposit),
            'tenor_months' => $data['tenor_months'],
            'interest_rate' => $data['interest_rate'],
            'max_price' => Money::fromMajor($result['max_price']),
            'currency' => config('lotlink.currency'),
        ]);
    }
}
