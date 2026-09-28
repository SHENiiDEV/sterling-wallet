import type { CurrencyCode } from '@/types';

export const currencySymbols: Record<string, string> = {
    USD: '$',
    EUR: '€',
    GBP: '£',
};

export function formatMoney(
    value: string | number | null | undefined,
    currency?: string,
    fractionDigits = 2,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);
    const amount = new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(Math.abs(number));
    // Sign before the symbol: -€25.00, not €-25.00.
    const sign = number < 0 && /[1-9]/.test(amount) ? '-' : '';

    if (!currency) {
        return `${sign}${amount}`;
    }

    const symbol = currencySymbols[currency];

    return symbol
        ? `${sign}${symbol}${amount}`
        : `${sign}${amount} ${currency}`;
}

/** Trims trailing zeros from a decimal string: "1.500" → "1.5". */
export function formatPercent(
    value: string | number | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `${Number(value).toString()}%`;
}

export function currencyOf(code: string): CurrencyCode {
    return code as CurrencyCode;
}
