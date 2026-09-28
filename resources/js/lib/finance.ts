// Budget maths (TDD M10), in whole naira. app/Domain/Finance/Support/FinanceCalculator.php
// has the same formulas for saved budgets; keep the two in step.

export interface FinanceDefaults {
    affordability_ratio: number;
    interest_rate: number;
    deposit_percent: number;
    tenor_months: number;
    tenors: number[];
}

const roundTo = (value: number, step: number) => Math.round(value / step) * step;
const floorTo = (value: number, step: number) => Math.floor(value / step) * step;

/** Monthly repayment: P·r / (1 − (1 + r)^−n), r = annual rate / 12. */
export function monthlyPayment(principal: number, annualRate: number, months: number): number {
    if (principal <= 0 || months <= 0) return 0;
    const r = annualRate / 100 / 12;
    const payment = r === 0 ? principal / months : (principal * r) / (1 - (1 + r) ** -months);
    return roundTo(payment, 500);
}

/** The loan a monthly payment supports: M · (1 − (1 + r)^−n) / r. */
export function principalFor(monthly: number, annualRate: number, months: number): number {
    if (monthly <= 0 || months <= 0) return 0;
    const r = annualRate / 100 / 12;
    return r === 0 ? monthly * months : (monthly * (1 - (1 + r) ** -months)) / r;
}

export function affordability(income: number, commitments: number, deposit: number, months: number, annualRate: number, ratio: number) {
    const monthly = Math.max(0, (income - commitments) * ratio);
    const loan = principalFor(monthly, annualRate, months);
    return {
        monthly: Math.round(monthly),
        loan: floorTo(loan, 50000),
        maxPrice: floorTo(loan + Math.max(0, deposit), 50000),
    };
}

/** Loan on a car price: deposit, monthly payment and the total paid over the loan. */
export function loanFor(price: number, depositPercent: number, months: number, annualRate: number) {
    const deposit = Math.round((price * depositPercent) / 100);
    const principal = Math.max(0, price - deposit);
    const monthly = monthlyPayment(principal, annualRate, months);
    return { deposit, principal, monthly, total: deposit + monthly * months, interest: Math.max(0, deposit + monthly * months - price) };
}

/** "₦12.4m", for chips and short labels. */
export function shortNaira(amount: number): string {
    if (amount >= 1_000_000) return `₦${(Math.floor(amount / 100_000) / 10).toString()}m`;
    if (amount >= 1_000) return `₦${Math.round(amount / 1000)}k`;
    return `₦${amount}`;
}
