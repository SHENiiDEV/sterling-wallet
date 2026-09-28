import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AppWordmark from '@/components/app-wordmark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const points = [
    'Daily reports collected and reconciled automatically',
    'USD, EUR and GBP MIDs in one ledger',
    'Every payout backed by a statement and a hash',
];

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <aside className="relative isolate hidden overflow-hidden bg-[oklch(0.24_0.08_266)] p-10 text-white lg:flex lg:flex-col lg:justify-between">
                <div
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-[radial-gradient(70%_60%_at_80%_10%,oklch(0.52_0.19_268/0.55),transparent_70%),radial-gradient(50%_50%_at_0%_100%,oklch(0.6_0.14_160/0.25),transparent_70%)]"
                />
                <Link href={home()} className="flex items-center">
                    <AppWordmark markVariant="light" />
                </Link>

                <div className="max-w-md">
                    <p className="font-display text-4xl leading-tight">
                        Merchant settlements,{' '}
                        <span className="text-white/60 italic">
                            without the spreadsheets.
                        </span>
                    </p>
                    <ul className="mt-8 grid gap-3 text-sm text-white/75">
                        {points.map((point) => (
                            <li key={point} className="flex items-center gap-3">
                                <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                                    <Check className="size-3" />
                                </span>
                                {point}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="text-xs text-white/45">
                    Access is issued by your administrator.
                </p>
            </aside>

            <main className="flex flex-col items-center justify-center p-6 md:p-10">
                <div className="w-full max-w-sm">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col gap-4">
                            <Link href={home()} className="lg:hidden">
                                <AppWordmark size="sm" />
                                <span className="sr-only">Sterling Pay</span>
                            </Link>
                            <div className="space-y-1.5">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {title}
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
