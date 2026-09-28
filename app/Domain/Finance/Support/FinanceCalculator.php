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

    /**
     * Yearly running costs on top of the price. Estimates only.
     *
     * @return array{items: list<array{label: string, amount: int}>, total: int}
     */
    public static function ownership(int $price, ?int $engineCc, ?int $year): array
    {
        /** @var array{insurance_percent: float, papers: int, fuel_price: int, km_per_month: int, km_per_litre: array<int, int>, servicing: array<int, int>} $f */
        $f = config('lotlink.finance');

        $kmPerLitre = self::band($f['km_per_litre'], $engineCc ?: 2000, 8);
        $servicing = self::band($f['servicing'], $year ? max(0, (int) now()->year - $year) : 5, 600000);

        $items = [
            ['label' => 'Insurance (comprehensive)', 'amount' => self::roundTo($price * $f['insurance_percent'] / 100, 1000)],
            ['label' => 'Registration and papers', 'amount' => (int) $f['papers']],
            ['label' => 'Fuel', 'amount' => self::roundTo($f['km_per_month'] * 12 / $kmPerLitre * $f['fuel_price'], 1000)],
            ['label' => 'Servicing and repairs', 'amount' => (int) $servicing],
        ];

        return ['items' => $items, 'total' => array_sum(array_column($items, 'amount'))];
    }

    /** The value for the first band whose upper limit covers $value. @param array<int, int> $bands */
    private static function band(array $bands, int $value, int $fallback): int
    {
        foreach ($bands as $upTo => $result) {
            if ($value <= $upTo) {
                return $result;
            }
        }

        return $fallback;
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
