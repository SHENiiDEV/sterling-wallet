import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    tone?: 'default' | 'brand' | 'success' | 'warning' | 'danger';
}) {
    const toneClass = {
        default: 'bg-muted text-muted-foreground',
        brand: 'bg-brand/10 text-brand',
        success: 'bg-success/12 text-success',
        warning: 'bg-warning/15 text-warning',
        danger: 'bg-destructive/10 text-destructive-foreground',
    }[tone];

    return (
        <div className="rounded-xl border bg-card p-5 shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'flex size-8 items-center justify-center rounded-lg',
                            toneClass,
                        )}
                    >
                        <Icon className="size-4" />
                    </span>
                )}
            </div>
            <p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
