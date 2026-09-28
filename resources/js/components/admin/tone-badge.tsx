import { StatusBadge } from '@/components/admin/status-badge';
import { cn } from '@/lib/utils';
import type { MerchantStatus, MidStatus, StatusColor } from '@/types';

const merchantColors: Record<MerchantStatus, StatusColor> = {
    onboarding: 'blue',
    review: 'amber',
    active: 'emerald',
    suspended: 'orange',
    closed: 'slate',
};

const midColors: Record<MidStatus, StatusColor> = {
    active: 'emerald',
    inactive: 'slate',
    review: 'amber',
};

export function MerchantStatusBadge({
    status,
    label,
}: {
    status: MerchantStatus;
    label: string;
}) {
    return <StatusBadge name={label} color={merchantColors[status]} />;
}

export function MidStatusBadge({
    status,
    label,
}: {
    status: MidStatus;
    label: string;
}) {
    return <StatusBadge name={label} color={midColors[status]} />;
}

const currencyClasses: Record<string, string> = {
    USD: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/25 dark:text-emerald-300',
    EUR: 'bg-blue-500/10 text-blue-700 ring-blue-500/25 dark:text-blue-300',
    GBP: 'bg-violet-500/10 text-violet-700 ring-violet-500/25 dark:text-violet-300',
};

export function CurrencyBadge({
    currency,
    count,
    className,
}: {
    currency: string;
    count?: number;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold ring-1 ring-inset',
                currencyClasses[currency] ??
                    'bg-muted text-muted-foreground ring-border',
                className,
            )}
        >
            {currency}
            {count !== undefined && count > 1 && (
                <span className="opacity-70">×{count}</span>
            )}
        </span>
    );
}
