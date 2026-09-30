export function formatNaira(amount: number | null | undefined): string {
    if (amount === null || amount === undefined || Number.isNaN(amount)) return '';
    return '₦' + Math.round(amount).toLocaleString('en-NG');
}

export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : value.toLocaleString('en-NG');
}

/** "₦12,500,000" / "12500000" → 12500000 */
/**
 * Whole naira from what someone typed: "₦1,500,000" → 1500000. Kobo is ignored ("1500.50" → 1500, never 150050)
 * and anything with letters gives null; the form's own check (v-field money) explains the problem.
 */
export function parseAmount(input: string): number | null {
    const clean = input
        .trim()
        .replace(/^(₦|NGN|N)\s*/iu, '')
        .replace(/[,\s]/g, '');
    const match = /^(\d+)(\.\d*)?$/.exec(clean);

    return match ? Number(match[1]) : null;
}

/** For inputs that reformat as you type ("₦1,300,000"): a stray letter or symbol just doesn't take, instead of clearing the amount. */
export function typedAmount(input: string): number | null {
    return parseAmount(input.replace(/[^\d.,\s₦]/gu, ''));
}
