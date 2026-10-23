import { cn } from '@/lib/utils';

/** A bar with a percentage; `goal` bars go green when the target is met, limit bars go red when it is exceeded. */
export function Meter({
    percent,
    goal = false,
    empty,
}: {
    percent: number | null;
    goal?: boolean;
    empty: string;
}) {
    if (percent === null) {
        return <span className="text-xs text-muted-foreground">{empty}</span>;
    }

    const color = goal
        ? percent >= 100
            ? 'bg-emerald-500'
            : percent >= 50
              ? 'bg-amber-500'
              : 'bg-rose-500'
        : percent >= 100
          ? 'bg-rose-500'
          : percent >= 80
            ? 'bg-amber-500'
            : 'bg-emerald-500';

    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn('h-full rounded-full', color)}
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
            <span className="text-xs tabular-nums">{percent}%</span>
        </div>
    );
}
