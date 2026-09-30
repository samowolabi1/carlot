/**
 * What each kind of form field may hold, mirroring app/Domain/Support/Fields.php (patterns, lengths, messages).
 * tests/Unit/FieldsTest.php checks the two stay in step. The server always validates again; this is so people
 * see a problem as they type instead of after sending.
 */

export type FieldKind =
    | 'person_name'
    | 'business_name'
    | 'place'
    | 'model'
    | 'reference'
    | 'code'
    | 'phone'
    | 'email'
    | 'money'
    | 'count'
    | 'otp'
    | 'new_password'
    | 'text'
    | 'vin'
    | 'account_number';

export interface FieldSpec {
    /** What the value must look like (tested on the trimmed value). */
    pattern?: RegExp;
    /** Plain words for the label: "Full name <message>." */
    message?: string;
    min?: number;
    max?: number;
    inputmode?: 'text' | 'tel' | 'email' | 'numeric' | 'decimal';
    autocomplete?: string;
    /** Extra check with its own message. */
    check?: (value: string, options: FieldOptions) => string | null;
}

export interface FieldOptions {
    kind: FieldKind;
    /** Overrides the kind's length limits (characters) or, for money/count, the value range. */
    max?: number;
    min?: number;
    /** Used in messages; defaults to the input's label text. */
    label?: string;
}

/** Largest amount any money field takes, in whole naira (Fields::MONEY_MAX). */
export const MONEY_MAX = 5_000_000_000;

export const FIELDS: Record<FieldKind, FieldSpec> = {
    person_name: {
        pattern: /^[\p{L}\p{M}][\p{L}\p{M}'’. \-]*$/u,
        message: 'can only contain letters, spaces, hyphens and apostrophes',
        min: 2,
        max: 80,
        autocomplete: 'name',
    },
    business_name: {
        pattern: /^(?=.*\p{L})[\p{L}\p{M}\p{N}&'’.,()\/ \-]+$/u,
        message: "needs letters, and can only contain letters, numbers, spaces and & ' . , ( ) / -",
        min: 2,
        max: 120,
        autocomplete: 'organization',
    },
    place: {
        pattern: /^[\p{L}\p{M}][\p{L}\p{M}'’. \-]*$/u,
        message: 'can only contain letters, spaces, hyphens and apostrophes',
        min: 2,
        max: 80,
    },
    model: {
        pattern: /^[\p{L}\p{N}][\p{L}\p{N}\/.+&() \-]*$/u,
        message: 'can only contain letters, numbers, spaces and - / . + &',
        max: 60,
    },
    reference: {
        pattern: /^[A-Za-z0-9][A-Za-z0-9\/_.#: \-]*$/u,
        message: 'can only contain letters, numbers, spaces and - / _ . # :',
        max: 64,
    },
    code: {
        pattern: /^[A-Za-z0-9][A-Za-z0-9\-]*$/u,
        message: 'can only contain letters, numbers and hyphens',
        max: 32,
    },
    phone: {
        pattern: /^\+?[0-9 ()\-]{7,20}$/u,
        message: 'must look like 0803 123 4567 or +234 803 123 4567',
        max: 20,
        inputmode: 'tel',
        autocomplete: 'tel',
        check: (value) => (nigerianLooksShort(value) ? 'looks too short. Nigerian mobile numbers have 11 digits, e.g. 0803 123 4567' : null),
    },
    email: {
        // The server checks it properly (RFC); this catches the everyday slips.
        pattern: /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u,
        message: 'must look like ada@example.com',
        max: 190,
        inputmode: 'email',
        autocomplete: 'email',
    },
    money: {
        pattern: /^(₦|NGN|N)?\s*\d[\d,\s]*(\.0+)?$/iu,
        message: 'must be an amount in whole naira, e.g. 1,500,000',
        max: 16,
        inputmode: 'numeric',
        check: (value, o) => rangeMessage(parseMoney(value), o.min ?? 1, o.max ?? MONEY_MAX, true),
    },
    count: {
        pattern: /^\d[\d,]*$/u,
        message: 'must be a whole number',
        max: 12,
        inputmode: 'numeric',
        check: (value, o) => rangeMessage(parseMoney(value), o.min ?? 0, o.max ?? Number.MAX_SAFE_INTEGER, false),
    },
    otp: {
        pattern: /^\d{6}$/u,
        message: 'must be the 6-digit code we sent',
        max: 6,
        inputmode: 'numeric',
        autocomplete: 'one-time-code',
    },
    new_password: {
        min: 8,
        max: 72,
        autocomplete: 'new-password',
        check: (value) => (/\p{L}/u.test(value) && /\d/u.test(value) ? null : 'needs at least one letter and one number'),
    },
    text: {},
    // Spaces are fine while typing: the server takes them out.
    vin: {
        max: 20,
        check: (value) => (/^[A-HJ-NPR-Z0-9]{17}$/iu.test(value.replace(/\s+/g, '')) ? null : 'must be 17 letters and numbers (VINs never use I, O or Q)'),
    },
    account_number: {
        pattern: /^[\d ]+$/u,
        message: 'can only contain digits',
        max: 14,
        inputmode: 'numeric',
        check: (value) => (value.replace(/\D/g, '').length === 10 ? null : 'must be the 10-digit NUBAN account number'),
    },
};

/** "₦1,500,000" → 1500000; NaN when it isn't a whole amount. */
export function parseMoney(value: string): number {
    const clean = value
        .trim()
        .replace(/^(₦|NGN|N)\s*/iu, '')
        .replace(/[,\s]/g, '')
        .replace(/\.0+$/, '');

    return /^\d+$/.test(clean) ? Number(clean) : Number.NaN;
}

/** Whole naira shown with thousands separators: 1500000 → "1,500,000". */
export function formatMoney(value: number): string {
    return value.toLocaleString('en-NG');
}

function rangeMessage(value: number, min: number, max: number, naira: boolean): string | null {
    if (Number.isNaN(value)) return null;
    const show = (n: number) => (naira ? `₦${formatMoney(n)}` : formatMoney(n));
    if (value < min) return `must be at least ${show(min)}`;
    if (value > max) return `can't be more than ${show(max)}`;

    return null;
}

/** 0803… numbers need 11 digits; +234 numbers 13. Other countries are left to the server. */
function nigerianLooksShort(value: string): boolean {
    const digits = value.replace(/\D/g, '');
    if (value.trim().startsWith('+')) return digits.startsWith('234') && digits.length < 13;

    return digits.startsWith('0') && digits.length < 11;
}

/**
 * The problem with a value, in words for "<label> <problem>.", or null when it's fine.
 * Empty values are left to `required` (the browser and the server both check that).
 */
export function fieldProblem(raw: string, options: FieldOptions): string | null {
    const value = raw.trim();
    if (value === '') return null;

    const spec = FIELDS[options.kind];
    const isNumber = options.kind === 'money' || options.kind === 'count';
    const min = isNumber ? undefined : (options.min ?? spec.min);
    const max = isNumber ? spec.max : (options.max ?? spec.max);

    if (min !== undefined && [...value].length < min) return `needs at least ${min} characters`;
    if (max !== undefined && [...value].length > max) return `can't be longer than ${max} characters`;
    if (spec.pattern && !spec.pattern.test(value)) return spec.message ?? 'is not in the right format';

    return spec.check?.(value, options) ?? null;
}
