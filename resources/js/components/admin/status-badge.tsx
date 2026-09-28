import { cn } from '@/lib/utils';
import type { StatusColor } from '@/types';

export const statusColorClasses: Record<
    StatusColor,
    { badge: string; dot: string; bar: string }
> = {
    slate: {
        badge: 'bg-slate-500/10 text-slate-700 ring-slate-500/20 dark:text-slate-300',
        dot: 'bg-slate-500',
        bar: 'bg-slate-400',
    },
    blue: {
        badge: 'bg-blue-500/10 text-blue-700 ring-blue-500/20 dark:text-blue-300',
        dot: 'bg-blue-500',
        bar: 'bg-blue-500',
    },
    cyan: {
        badge: 'bg-cyan-500/10 text-cyan-700 ring-cyan-500/20 dark:text-cyan-300',
        dot: 'bg-cyan-500',
        bar: 'bg-cyan-500',
    },
    emerald: {
        badge: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
        dot: 'bg-emerald-500',
        bar: 'bg-emerald-500',
    },
    amber: {
        badge: 'bg-amber-500/10 text-amber-800 ring-amber-500/25 dark:text-amber-300',
        dot: 'bg-amber-500',
        bar: 'bg-amber-500',
    },
    orange: {
        badge: 'bg-orange-500/10 text-orange-700 ring-orange-500/20 dark:text-orange-300',
        dot: 'bg-orange-500',
        bar: 'bg-orange-500',
    },
    rose: {
        badge: 'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300',
        dot: 'bg-rose-500',
        bar: 'bg-rose-500',
    },
    violet: {
        badge: 'bg-violet-500/10 text-violet-700 ring-violet-500/20 dark:text-violet-300',
        dot: 'bg-violet-500',
        bar: 'bg-violet-500',
    },
};

export function StatusDot({
    color,
    className,
}: {
    color: StatusColor;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-block size-2 shrink-0 rounded-full',
                statusColorClasses[color]?.dot ?? statusColorClasses.slate.dot,
                className,
            )}
        />
    );
}

export function StatusBadge({
    name,
    color,
    className,
}: {
    name: string;
    color: StatusColor;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                statusColorClasses[color]?.badge ??
                    statusColorClasses.slate.badge,
                className,
            )}
        >
            <StatusDot color={color} className="size-1.5" />
            {name}
        </span>
    );
}
