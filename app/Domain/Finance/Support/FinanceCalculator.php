<?php

namespace App\Domain\Finance\Support;

/**
 * The budget maths (TDD M10), in whole naira. resources/js/lib/finance.ts has the same
 * formulas for the calculators in the browser; keep the two in step.
 */
final class FinanceCalculator
{
    /** Monthly repayment on a loan: P·r / (1 − (1 + r)^−n), r = annual rate / 12. */
    public static function monthlyPayment(int $principal, float $annualRate, int $months): int
    {
        if ($principal <= 0 || $months <= 0) {
            return 0;
        }

        $r = $annualRate / 100 / 12;
        $payment = $r == 0.0 ? $principal / $months : $principal * $r / (1 - (1 + $r) ** -$months);

        return self::roundTo($payment, 500);
    }

    /** The loan a monthly payment supports: M · (1 − (1 + r)^−n) / r. */
    public static function principalFor(float $monthly, float $annualRate, int $months): float
    {
        if ($monthly <= 0 || $months <= 0) {
            return 0;
        }

        $r = $annualRate / 100 / 12;

        return $r == 0.0 ? $monthly * $months : $monthly * (1 - (1 + $r) ** -$months) / $r;
    }

    /**
     * What a buyer can afford: repayments capped at a share of what is left after
     * commitments, plus the deposit.
     *
     * @return array{monthly: int, loan: int, max_price: int}
     */
    public static function affordability(int $income, int $commitments, int $deposit, int $months, float $annualRate): array
    {
        $monthly = max(0, ($income - $commitments) * (float) config('lotlink.finance.affordability_ratio'));
        $loan = self::principalFor($monthly, $annualRate, $months);

        return [
            'monthly' => (int) round($monthly),
            'loan' => self::floorTo($loan, 50000),
            'max_price' => self::floorTo($loan + max(0, $deposit), 50000),
        ];
    }

    /**
     * "From ₦X/mo" with the default deposit, tenor and rate.
     *
     * @return array{monthly: int, deposit_percent: int, months: int, rate: float}
     */
    public static function fromPrice(int $price): array
    {
        $percent = (int) config('lotlink.finance.deposit_percent');
        $months = (int) config('lotlink.finance.tenor_months');
        $rate = (float) config('lotlink.finance.interest_rate');

        return [
            'monthly' => self::monthlyPayment((int) round($price * (100 - $percent) / 100), $rate, $months),
            'deposit_percent' => $percent,
            'months' => $months,
            'rate' => $rate,
        ];
    }

    private static function roundTo(float $value, int $step): int
    {
        return (int) (round($value / $step) * $step);
    }

    private static function floorTo(float $value, int $step): int
    {
        return (int) (floor($value / $step) * $step);
    }
}
