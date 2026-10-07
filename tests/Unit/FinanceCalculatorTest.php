<?php

use App\Domain\Finance\Support\FinanceCalculator;

it('works out what a buyer can afford, as in the design', function () {
    // Income ₦1.3m, commitments ₦330k, deposit ₦3.75m, 36 months at 24%.
    expect(FinanceCalculator::affordability(1_300_000, 330_000, 3_750_000, 36, 24))
        ->toBe(['monthly' => 339_500, 'loan' => 8_650_000, 'max_price' => 12_400_000]);
});

it('never goes negative when commitments exceed income', function () {
    expect(FinanceCalculator::affordability(200_000, 300_000, 1_000_000, 36, 24))
        ->toBe(['monthly' => 0, 'loan' => 0, 'max_price' => 1_000_000]);
});

it('calculates the monthly payment from a price with the default terms', function () {
    // ₦12.5m, 30% down, 36 months at 24%: about ₦343,000 a month.
    expect(FinanceCalculator::fromPrice(12_500_000))->toBe(['monthly' => 343_500, 'deposit_percent' => 30, 'months' => 36, 'rate' => 24.0]);
});

it('handles an interest-free loan', function () {
    expect(FinanceCalculator::monthlyPayment(1_200_000, 0, 12))->toBe(100_000)
        ->and(FinanceCalculator::principalFor(100_000, 0, 12))->toBe(1_200_000.0);
});
