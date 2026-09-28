import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function FormSection({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'grid gap-6 rounded-xl border bg-card p-5 shadow-xs md:grid-cols-[14rem_minmax(0,1fr)] md:p-6',
                className,
            )}
        >
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            <div className="grid content-start gap-4 sm:grid-cols-2">
                {children}
            </div>
        </section>
    );
}

export function Field({
    label,
    htmlFor,
    error,
    hint,
    children,
    className,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

export function AffixInput({
    prefix,
    suffix,
    className,
    ...props
}: React.ComponentProps<typeof Input> & {
    prefix?: ReactNode;
    suffix?: ReactNode;
}) {
    return (
        <div className="relative">
            {prefix && (
                <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                    {prefix}
                </span>
            )}
            <Input
                className={cn(
                    'tabular-nums',
                    prefix && 'pl-8',
                    suffix && 'pr-12',
                    className,
                )}
                inputMode="decimal"
                {...props}
            />
            {suffix && (
                <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs font-medium text-muted-foreground">
                    {suffix}
                </span>
            )}
        </div>
    );
}
