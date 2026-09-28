export function formatNaira(amount: number | null | undefined): string {
    if (amount === null || amount === undefined || Number.isNaN(amount)) return '';
    return '₦' + Math.round(amount).toLocaleString('en-NG');
}

export function formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '' : value.toLocaleString('en-NG');
}

/** "₦12,500,000" / "12500000" → 12500000 */
export function parseAmount(input: string): number | null {
    const digits = input.replace(/[^\d]/g, '');
    return digits ? Number(digits) : null;
}
