import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Bot,
    Check,
    FileSignature,
    Fingerprint,
    GitCompareArrows,
    KeyRound,
    Layers,
    LineChart,
    Lock,
    ScrollText,
    ShieldCheck,
    Store,
    Wallet,
} from 'lucide-react';
import AppWordmark from '@/components/app-wordmark';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import admin from '@/routes/admin';

const features = [
    {
        icon: Layers,
        title: 'One merchant, many currencies',
        text: 'Run USD, EUR and GBP MIDs under a single merchant. Each MID keeps its own currency, reports and reserve — tariffs stay in one place.',
    },
    {
        icon: Bot,
        title: 'Reports that collect themselves',
        text: 'Built-in workers sign in to acquirer and gateway portals, download daily exports and file them against the right MID — no manual uploads.',
    },
    {
        icon: GitCompareArrows,
        title: 'Clearing ↔ gateway reconciliation',
        text: 'Every clearing line is matched one-to-one with its gateway transaction by card, amount, currency and time window.',
    },
    {
        icon: ShieldCheck,
        title: 'Rolling reserve, to the cent',
        text: 'Reserve is held per currency against a configurable limit, logged as a ledger and released on schedule — never double-counted.',
    },
    {
        icon: Wallet,
        title: 'USDC settlements',
        text: 'Build a statement, apply manual adjustments, attach the transaction hash and mark it settled. The merchant sees it instantly.',
    },
    {
        icon: FileSignature,
        title: 'Document Center',
        text: 'Contracts and KYB packs move through your own pipeline — Draft, WIP, Passed to merchant, Signing — with full history.',
    },
];

const steps = [
    {
        title: 'Collect',
        text: 'Workers pull clearing and gateway reports for every live MID as soon as they are published.',
    },
    {
        title: 'Reconcile',
        text: 'Operations are split by MID, classified as sale, refund, decline or chargeback and matched across sources.',
    },
    {
        title: 'Calculate',
        text: 'Merchant fees, provider costs, reserve and conversion are computed once — the same numbers feed every screen.',
    },
    {
        title: 'Settle',
        text: 'A daily statement goes to the merchant and the payout is released in USDC with a verifiable hash.',
    },
];

const statement = [
    { label: 'Gross volume · 100 txns', value: '10,000.00', tone: 'plain' },
    { label: 'Merchant fee · Visa EU 3%', value: '300.00', tone: 'muted' },
    { label: 'Rolling reserve · 10%', value: '970.00', tone: 'muted' },
    { label: 'Conversion to USDC · 0.40%', value: '34.92', tone: 'muted' },
] as const;

export default function Welcome() {
    const { auth } = usePage().props;
    const consoleHref = auth.user ? admin.dashboard() : login();

    return (
        <>
            <Head title="Multi-currency merchant settlements" />
            <div className="min-h-screen overflow-x-clip bg-background text-foreground">
                <SiteHeader signedIn={Boolean(auth.user)} />

                <main>
                    {/* Hero */}
                    <section className="relative isolate">
                        <div
                            aria-hidden
                            className="absolute inset-x-0 -top-24 -z-10 h-[720px] bg-[radial-gradient(60%_60%_at_70%_20%,color-mix(in_oklch,var(--brand)_22%,transparent),transparent_70%),radial-gradient(40%_50%_at_10%_10%,color-mix(in_oklch,var(--success)_14%,transparent),transparent_70%)]"
                        />
                        <div
                            aria-hidden
                            className="absolute inset-0 -z-10 bg-[linear-gradient(to_right,var(--border)_1px,transparent_1px),linear-gradient(to_bottom,var(--border)_1px,transparent_1px)] mask-[radial-gradient(ellipse_at_top,black_20%,transparent_65%)] bg-size-[56px_56px] opacity-50"
                        />

                        <div className="mx-auto grid max-w-6xl gap-14 px-4 pt-16 pb-20 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:items-center lg:gap-10 lg:pt-24 lg:pb-28">
                            <div>
                                <span className="inline-flex items-center gap-2 rounded-full border bg-card/70 px-3 py-1 text-xs font-medium text-muted-foreground backdrop-blur">
                                    <span className="size-1.5 rounded-full bg-success" />
                                    USD · EUR · GBP merchant settlements
                                </span>
                                <h1 className="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                                    Settle merchants in{' '}
                                    <span className="font-display font-normal text-brand italic">
                                        hours,
                                    </span>{' '}
                                    not weeks.
                                </h1>
                                <p className="mt-6 max-w-xl text-lg leading-relaxed text-pretty text-muted-foreground">
                                    Sterling Pay collects acquirer reports,
                                    reconciles every transaction, holds rolling
                                    reserve and pays out in USDC — with each fee
                                    traceable back to the card that paid it.
                                </p>
                                <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                    <Link
                                        href={consoleHref}
                                        className="group inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90"
                                    >
                                        {auth.user
                                            ? 'Open console'
                                            : 'Sign in to console'}
                                        <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                    </Link>
                                    <a
                                        href="#how-it-works"
                                        className="inline-flex h-11 items-center justify-center rounded-lg border bg-card px-5 text-sm font-medium transition hover:bg-muted"
                                    >
                                        See how it works
                                    </a>
                                </div>
                                <ul className="mt-10 grid max-w-lg grid-cols-1 gap-2.5 text-sm text-muted-foreground sm:grid-cols-2">
                                    {[
                                        'Visa & Mastercard, EU / non-EU pricing',
                                        'Daily statements to every merchant',
                                        'Reserve tracked per currency',
                                        'Payout hash on every settlement',
                                    ].map((item) => (
                                        <li
                                            key={item}
                                            className="flex items-center gap-2"
                                        >
                                            <Check className="size-4 shrink-0 text-success" />
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <HeroStatement />
                        </div>
                    </section>

                    {/* Currency strip */}
                    <section className="border-y bg-muted/30">
                        <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-6 px-4 py-8 sm:px-6 md:flex-row">
                            <p className="text-sm text-muted-foreground">
                                One ledger for every MID and currency
                            </p>
                            <div className="flex flex-wrap items-center justify-center gap-3">
                                {[
                                    ['$', 'USD MID'],
                                    ['€', 'EUR MID'],
                                    ['£', 'GBP MID'],
                                ].map(([symbol, label]) => (
                                    <span
                                        key={label}
                                        className="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1.5 text-sm font-medium"
                                    >
                                        <span className="flex size-6 items-center justify-center rounded-full bg-brand/10 text-brand">
                                            {symbol}
                                        </span>
                                        {label}
                                    </span>
                                ))}
                                <ArrowRight className="size-4 text-muted-foreground" />
                                <span className="inline-flex items-center gap-2 rounded-full bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground">
                                    <span className="flex size-6 items-center justify-center rounded-full bg-primary-foreground/15">
                                        ◎
                                    </span>
                                    USDC payout
                                </span>
                            </div>
                        </div>
                    </section>

                    {/* Features */}
                    <section id="features" className="scroll-mt-20">
                        <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                            <SectionIntro
                                eyebrow="Platform"
                                title="Everything between the card swipe and the payout"
                                text="The back office for a payment facilitator: data in from acquirers and gateways, money out to merchants, and the numbers in between you can defend."
                            />
                            <div className="mt-14 grid gap-px overflow-hidden rounded-2xl border bg-border sm:grid-cols-2 lg:grid-cols-3">
                                {features.map((feature) => (
                                    <div
                                        key={feature.title}
                                        className="group bg-card p-7 transition-colors hover:bg-muted/40"
                                    >
                                        <span className="flex size-10 items-center justify-center rounded-xl bg-brand/10 text-brand transition-transform group-hover:-translate-y-0.5">
                                            <feature.icon className="size-5" />
                                        </span>
                                        <h3 className="mt-5 font-semibold">
                                            {feature.title}
                                        </h3>
                                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                            {feature.text}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* How it works */}
                    <section
                        id="how-it-works"
                        className="scroll-mt-20 border-y bg-muted/30"
                    >
                        <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                            <SectionIntro
                                eyebrow="How it works"
                                title="From raw report to settled payout"
                                text="Four steps run every business day. Weekends and bank holidays roll into a single report automatically."
                            />
                            <ol className="mt-14 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                                {steps.map((step, index) => (
                                    <li key={step.title} className="relative">
                                        {index < steps.length - 1 && (
                                            <span
                                                aria-hidden
                                                className="absolute top-5 left-12 hidden h-px w-[calc(100%-2.5rem)] bg-linear-to-r from-border to-transparent lg:block"
                                            />
                                        )}
                                        <span className="relative flex size-10 items-center justify-center rounded-full border bg-card font-display text-lg text-brand italic shadow-xs">
                                            {index + 1}
                                        </span>
                                        <h3 className="mt-5 font-semibold">
                                            {step.title}
                                        </h3>
                                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                            {step.text}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>

                    {/* Transparency */}
                    <section>
                        <div className="mx-auto grid max-w-6xl gap-12 px-4 py-24 sm:px-6 lg:grid-cols-2 lg:items-center">
                            <div>
                                <SectionIntro
                                    align="left"
                                    eyebrow="Transparency"
                                    title="Profit you can explain line by line"
                                    text="Revenue, provider cost and margin are calculated once per daily report and stored. Dashboards, monthly statements and partner shares all read the same figures — so they always agree."
                                />
                                <ul className="mt-8 grid gap-4">
                                    {[
                                        [
                                            LineChart,
                                            'Margin per merchant, MID and day',
                                        ],
                                        [
                                            Store,
                                            'Merchant portal with statements and reserve',
                                        ],
                                        [
                                            ScrollText,
                                            'Monthly statements that lock once closed',
                                        ],
                                    ].map(([Icon, text]) => {
                                        const I = Icon as typeof LineChart;

                                        return (
                                            <li
                                                key={text as string}
                                                className="flex items-center gap-3 text-sm"
                                            >
                                                <span className="flex size-8 items-center justify-center rounded-lg bg-success/12 text-success">
                                                    <I className="size-4" />
                                                </span>
                                                {text as string}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                            <ProfitBreakdown />
                        </div>
                    </section>

                    {/* Security */}
                    <section
                        id="security"
                        className="scroll-mt-20 bg-[oklch(0.24_0.08_266)] text-white dark:border-y dark:bg-[oklch(0.2_0.04_266)]"
                    >
                        <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                            <div className="max-w-2xl">
                                <p className="text-sm font-medium tracking-wide text-white/60 uppercase">
                                    Security
                                </p>
                                <h2 className="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                                    Built for money that isn’t yours
                                </h2>
                                <p className="mt-4 text-white/70">
                                    Merchant funds and credentials deserve more
                                    than a password field. Access is narrow by
                                    default and every sensitive action leaves a
                                    trace.
                                </p>
                            </div>
                            <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {[
                                    [
                                        Fingerprint,
                                        'Passkeys & 2FA',
                                        'Hardware-backed sign-in and TOTP for every admin.',
                                    ],
                                    [
                                        KeyRound,
                                        'Encrypted secrets',
                                        'Portal logins and wallet seeds encrypted at rest.',
                                    ],
                                    [
                                        Lock,
                                        'Scoped access',
                                        'Merchants see only their own company — nothing else.',
                                    ],
                                    [
                                        BadgeCheck,
                                        'Audit trail',
                                        'Status changes, payouts and seed views are logged.',
                                    ],
                                ].map(([Icon, title, text]) => {
                                    const I = Icon as typeof Lock;

                                    return (
                                        <div
                                            key={title as string}
                                            className="rounded-xl border border-white/10 bg-white/5 p-5"
                                        >
                                            <I className="size-5 text-white/80" />
                                            <h3 className="mt-4 font-semibold">
                                                {title as string}
                                            </h3>
                                            <p className="mt-1.5 text-sm text-white/65">
                                                {text as string}
                                            </p>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </section>

                    {/* CTA */}
                    <section>
                        <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                            <div className="relative isolate overflow-hidden rounded-3xl border bg-card px-6 py-14 text-center shadow-sm sm:px-12">
                                <div
                                    aria-hidden
                                    className="absolute inset-0 -z-10 bg-[radial-gradient(50%_80%_at_50%_0%,color-mix(in_oklch,var(--brand)_16%,transparent),transparent)]"
                                />
                                <h2 className="mx-auto max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                                    Your merchants,{' '}
                                    <span className="font-display font-normal text-brand italic">
                                        settled
                                    </span>{' '}
                                    — every single day.
                                </h2>
                                <p className="mx-auto mt-4 max-w-xl text-muted-foreground">
                                    Sterling Pay is invite-only. Team members
                                    and merchants sign in with the access issued
                                    by your administrator.
                                </p>
                                <Link
                                    href={consoleHref}
                                    className="group mt-8 inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-primary px-6 text-sm font-medium text-primary-foreground shadow-sm transition hover:opacity-90"
                                >
                                    {auth.user ? 'Open console' : 'Sign in'}
                                    <ArrowRight className="size-4 transition-transform group-hover:translate-x-0.5" />
                                </Link>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:px-6">
                        <Brand />
                        <p>
                            © {new Date().getFullYear()} Sterling Pay. All
                            rights reserved.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}

function Brand() {
    return <AppWordmark size="sm" className="text-foreground" />;
}

function SiteHeader({ signedIn }: { signedIn: boolean }) {
    return (
        <header className="sticky top-0 z-40 border-b border-border/60 bg-background/90 backdrop-blur-lg">
            <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                <Link href="/" aria-label="Sterling Pay home">
                    <Brand />
                </Link>
                <nav className="hidden items-center gap-8 text-sm text-muted-foreground md:flex">
                    <a href="#features" className="hover:text-foreground">
                        Platform
                    </a>
                    <a href="#how-it-works" className="hover:text-foreground">
                        How it works
                    </a>
                    <a href="#security" className="hover:text-foreground">
                        Security
                    </a>
                </nav>
                <Link
                    href={signedIn ? admin.dashboard() : login()}
                    className="inline-flex h-9 items-center gap-1.5 rounded-lg border bg-card px-4 text-sm font-medium shadow-xs transition hover:bg-muted"
                >
                    {signedIn ? 'Console' : 'Sign in'}
                    <ArrowRight className="size-3.5" />
                </Link>
            </div>
        </header>
    );
}

function SectionIntro({
    eyebrow,
    title,
    text,
    align = 'center',
}: {
    eyebrow: string;
    title: string;
    text: string;
    align?: 'center' | 'left';
}) {
    return (
        <div
            className={cn(
                'max-w-2xl',
                align === 'center' && 'mx-auto text-center',
            )}
        >
            <p className="text-sm font-medium tracking-wide text-brand uppercase">
                {eyebrow}
            </p>
            <h2 className="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                {title}
            </h2>
            <p className="mt-4 leading-relaxed text-pretty text-muted-foreground">
                {text}
            </p>
        </div>
    );
}

function HeroStatement() {
    return (
        <div className="relative mx-auto w-full max-w-md lg:max-w-none">
            <div
                aria-hidden
                className="absolute -inset-4 -z-10 rounded-[2rem] bg-linear-to-br from-brand/20 via-transparent to-success/15 blur-2xl"
            />

            <div className="rounded-2xl border bg-card/90 shadow-2xl shadow-primary/10 backdrop-blur">
                <div className="flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Settlement statement
                        </p>
                        <p className="font-mono text-sm font-medium">
                            SET-2026-000123
                        </p>
                    </div>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-success/12 px-2.5 py-1 text-xs font-medium text-success">
                        <span className="size-1.5 animate-pulse rounded-full bg-success" />
                        Settled
                    </span>
                </div>

                <div className="grid gap-3 px-5 py-5 text-sm">
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span className="inline-flex items-center gap-1.5">
                            <span className="rounded bg-muted px-1.5 py-0.5 font-medium text-foreground">
                                EUR
                            </span>
                            MID 4400 1287
                        </span>
                        <span>Report · Wed 16 Sep</span>
                    </div>
                    {statement.map((row) => (
                        <div
                            key={row.label}
                            className="flex items-center justify-between gap-4"
                        >
                            <span className="text-muted-foreground">
                                {row.label}
                            </span>
                            <span
                                className={cn(
                                    'font-mono tabular-nums',
                                    row.tone === 'muted' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {row.tone === 'muted' && '− '}€{row.value}
                            </span>
                        </div>
                    ))}
                    <div className="mt-1 flex items-end justify-between gap-4 rounded-xl bg-muted/60 px-4 py-3">
                        <div>
                            <p className="text-xs text-muted-foreground">
                                Paid to merchant
                            </p>
                            <p className="font-mono text-2xl font-semibold tracking-tight tabular-nums">
                                8,695.08{' '}
                                <span className="text-base text-muted-foreground">
                                    USDC
                                </span>
                            </p>
                        </div>
                        <span className="font-mono text-[11px] text-muted-foreground">
                            0x9f3c…a41e
                        </span>
                    </div>
                </div>
            </div>

            <div className="absolute -top-10 right-6 hidden rotate-2 rounded-xl border bg-card px-3.5 py-2.5 shadow-lg sm:block">
                <p className="text-[11px] text-muted-foreground">
                    Reports collected
                </p>
                <p className="flex items-center gap-1.5 text-sm font-medium">
                    <Bot className="size-4 text-brand" />
                    Cardaq · Corefy
                    <Check className="size-3.5 text-success" />
                </p>
            </div>

            <div className="absolute -bottom-6 -left-4 hidden -rotate-2 rounded-xl border bg-card px-3.5 py-2.5 shadow-lg sm:block">
                <p className="text-[11px] text-muted-foreground">
                    Rolling reserve · GBP
                </p>
                <div className="mt-1.5 flex items-center gap-2">
                    <span className="h-1.5 w-28 overflow-hidden rounded-full bg-muted">
                        <span className="block h-full w-[42%] rounded-full bg-brand" />
                    </span>
                    <span className="text-xs font-medium tabular-nums">
                        42% of limit
                    </span>
                </div>
            </div>
        </div>
    );
}

function ProfitBreakdown() {
    const rows = [
        ['Merchant fee', '300.00', 'revenue'],
        ['Conversion fee', '34.92', 'revenue'],
        ['Acquirer cost', '150.00', 'cost'],
        ['Crypto provider cost', '21.83', 'cost'],
    ] as const;

    return (
        <div className="rounded-2xl border bg-card p-6 shadow-sm">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">Daily profit · one MID</p>
                <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground">
                    €10,000 volume
                </span>
            </div>
            <div className="mt-6 grid gap-3">
                {rows.map(([label, value, kind]) => (
                    <div key={label} className="grid gap-1.5">
                        <div className="flex justify-between text-sm">
                            <span className="text-muted-foreground">
                                {label}
                            </span>
                            <span className="font-mono tabular-nums">
                                {kind === 'cost' && '− '}€{value}
                            </span>
                        </div>
                        <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <span
                                className={cn(
                                    'block h-full rounded-full',
                                    kind === 'revenue'
                                        ? 'bg-success'
                                        : 'bg-muted-foreground/40',
                                )}
                                style={{
                                    width: `${(parseFloat(value) / 335) * 100}%`,
                                }}
                            />
                        </span>
                    </div>
                ))}
            </div>
            <div className="mt-6 flex items-end justify-between border-t pt-5">
                <div>
                    <p className="text-xs text-muted-foreground">Net profit</p>
                    <p className="font-mono text-3xl font-semibold tracking-tight tabular-nums">
                        €163.09
                    </p>
                </div>
                <p className="text-right text-xs text-muted-foreground">
                    Margin
                    <span className="block text-lg font-semibold text-success">
                        48.7%
                    </span>
                </p>
            </div>
        </div>
    );
}
