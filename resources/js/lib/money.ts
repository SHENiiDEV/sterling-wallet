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

    const amount = new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    }).format(Number(value));

    if (!currency) {
        return amount;
    }

    const symbol = currencySymbols[currency];

    return symbol ? `${symbol}${amount}` : `${amount} ${currency}`;
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
