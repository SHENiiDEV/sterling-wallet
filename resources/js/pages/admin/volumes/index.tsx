import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Terminal = {
    id: number;
    mid: string;
    currency: string;
    status: string;
    merchant: { public_id: string; name: string };
    pair: string;
    volume: number;
    approved: number;
    declined: number;
    approval_rate: number | null;
    limit: number | null;
    used_percent: number | null;
    norm: number | null;
    norm_derived: boolean;
    norm_percent: number | null;
    shortfall: number | null;
};

type Props = {
    view: 'day' | 'month';
    day: string | null;
    month: string;
    today: string;
    isToday: boolean;
    shortcuts: { date: string; label: string }[];
    previous: string;
    next: string | null;
    rows: Terminal[];
    totals: {
        currency: string;
        terminals: number;
        approved: number;
        declined: number;
        volume: number;
        norm: number | null;
    }[];
    selected: {
        terminal: Terminal;
        days: {
            date: string;
            approved: number;
            declined: number;
            volume: number;
            norm: number | null;
            norm_percent: number | null;
            cumulative: number;
            cumulative_percent: number | null;
        }[];
    } | null;
};

const monthLabel = (month: string) =>
    new Date(`${month}-01T12:00:00`).toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
    });

/** A bar with a percentage; `goal` bars go green when the target is met, limit bars go red when it is exceeded. */
function Meter({
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

export default function VolumesIndex({
    view,
    day,
    month,
    today,
    isToday,
    shortcuts,
    previous,
    next,
    rows,
    totals,
    selected,
}: Props) {
    const daily = view === 'day';
    const go = (query: Record<string, string | number | null>) =>
        router.get(
            admin.volumes.index.url(),
            Object.fromEntries(Object.entries(query).filter(([, v]) => v)),
            { preserveScroll: true, preserveState: true },
        );
    const current: Record<string, string | null> = daily
        ? { view, day }
        : { view, month };

    return (
        <>
            <Head title="Volumes" />
            <PageBody>
                <PageHeader
                    title="Volumes"
                    description="Successful gateway volume per terminal (MID) against its daily norm and monthly limit."
                    actions={
                        <div className="inline-flex rounded-lg border bg-card p-0.5 shadow-xs">
                            {(['day', 'month'] as const).map((v) => (
                                <button
                                    key={v}
                                    type="button"
                                    onClick={() =>
                                        go(
                                            v === 'day'
                                                ? { view: 'day', day: today }
                                                : { view: 'month', month },
                                        )
                                    }
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                        view === v
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {v === 'day' ? 'By day' : 'By month'}
                                </button>
                            ))}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    {daily &&
                        shortcuts.map((s) => (
                            <Button
                                key={s.date}
                                size="sm"
                                variant={s.date === day ? 'default' : 'outline'}
                                onClick={() => go({ view: 'day', day: s.date })}
                            >
                                {s.label}
                            </Button>
                        ))}
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            aria-label="Previous"
                            onClick={() =>
                                go(
                                    daily
                                        ? { view, day: previous }
                                        : { view, month: previous },
                                )
                            }
                        >
                            <ChevronLeft />
                        </Button>
                        {daily ? (
                            <Input
                                type="date"
                                value={day ?? ''}
                                max={today}
                                onChange={(e) =>
                                    e.target.value &&
                                    go({ view: 'day', day: e.target.value })
                                }
                                className="h-8 w-40"
                                aria-label="Day"
                            />
                        ) : (
                            <span className="min-w-36 text-center text-sm font-medium">
                                {monthLabel(month)}
                            </span>
                        )}
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            aria-label="Next"
                            disabled={next === null}
                            onClick={() =>
                                next &&
                                go(
                                    daily
                                        ? { view, day: next }
                                        : { view, month: next },
                                )
                            }
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                </div>

                {isToday && (
                    <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm">
                        Today is not final: the gateway files for a day arrive
                        after it ends, so the figures grow during the day.
                    </p>
                )}

                {totals.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {totals.map((t) => (
                            <div
                                key={t.currency}
                                className="rounded-xl border bg-card p-4 shadow-xs"
                            >
                                <div className="flex items-center justify-between">
                                    <CurrencyBadge currency={t.currency} />
                                    <span className="text-xs text-muted-foreground">
                                        {t.terminals} terminal(s)
                                    </span>
                                </div>
                                <p className="mt-2 text-2xl font-semibold tabular-nums">
                                    {formatMoney(t.volume, t.currency, 0)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t.approved} successful · {t.declined}{' '}
                                    declined
                                    {t.norm !== null &&
                                        ` · norm ${formatMoney(t.norm, t.currency, 0)}`}
                                </p>
                            </div>
                        ))}
                    </div>
                )}

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    {rows.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                            No terminals with a gateway yet.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">
                                        Terminal
                                    </TableHead>
                                    <TableHead>Merchant</TableHead>
                                    <TableHead className="text-right">
                                        Volume
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Successful
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Declined
                                    </TableHead>
                                    <TableHead>
                                        {daily ? 'Daily norm' : 'Norm'}
                                    </TableHead>
                                    {!daily && (
                                        <TableHead className="pr-5">
                                            Monthly limit
                                        </TableHead>
                                    )}
                                    {daily && (
                                        <TableHead className="pr-5 text-right">
                                            Missing to norm
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((t) => (
                                    <TableRow
                                        key={t.id}
                                        className={cn(
                                            'cursor-pointer',
                                            selected?.terminal.id === t.id &&
                                                'bg-accent/50',
                                        )}
                                        onClick={() =>
                                            go({ ...current, mid: t.id })
                                        }
                                    >
                                        <TableCell className="pl-5">
                                            <div className="flex items-center gap-2">
                                                <CurrencyBadge
                                                    currency={t.currency}
                                                />
                                                <span className="font-mono text-xs">
                                                    {t.mid}
                                                </span>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {t.pair}
                                            </div>
                                        </TableCell>
                                        <TableCell>{t.merchant.name}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(t.volume, t.currency)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {t.approved}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {t.declined}
                                            {t.approval_rate !== null &&
                                                ` · ${t.approval_rate}% ok`}
                                        </TableCell>
                                        <TableCell>
                                            <Meter
                                                goal
                                                percent={t.norm_percent}
                                                empty="no norm set"
                                            />
                                            {t.norm !== null && (
                                                <div className="text-xs text-muted-foreground">
                                                    of{' '}
                                                    {formatMoney(
                                                        t.norm,
                                                        t.currency,
                                                        0,
                                                    )}
                                                    {t.norm_derived &&
                                                        ' (limit ÷ days)'}
                                                </div>
                                            )}
                                        </TableCell>
                                        {!daily && (
                                            <TableCell className="pr-5">
                                                <Meter
                                                    percent={t.used_percent}
                                                    empty="no limit"
                                                />
                                            </TableCell>
                                        )}
                                        {daily && (
                                            <TableCell className="pr-5 text-right tabular-nums">
                                                {t.shortfall === null ? (
                                                    '—'
                                                ) : t.shortfall === 0 ? (
                                                    <span className="text-emerald-600">
                                                        norm met
                                                    </span>
                                                ) : (
                                                    formatMoney(
                                                        t.shortfall,
                                                        t.currency,
                                                        0,
                                                    )
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                {selected && (
                    <section className="grid gap-4">
                        <h2 className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {selected.terminal.merchant.name} ·{' '}
                            <span className="font-mono">
                                {selected.terminal.mid}
                            </span>
                            <span className="text-xs font-normal text-muted-foreground">
                                {daily
                                    ? `last ${selected.days.length} days`
                                    : monthLabel(month)}
                            </span>
                            <Link
                                href={admin.merchants.show(
                                    selected.terminal.merchant.public_id,
                                )}
                                className="text-xs font-normal text-primary hover:underline"
                            >
                                Merchant
                            </Link>
                        </h2>
                        <div className="rounded-xl border bg-card p-5 shadow-xs">
                            <h3 className="mb-3 text-sm font-semibold">
                                Successful volume per day
                            </h3>
                            <BarChart
                                label="Successful volume per day"
                                points={selected.days.map((d) => ({
                                    date: d.date,
                                    value: d.volume,
                                }))}
                                format={(v) =>
                                    formatMoney(
                                        v,
                                        selected.terminal.currency,
                                        0,
                                    )
                                }
                            />
                        </div>
                        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead className="pl-5">
                                            Day
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Successful
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Declined
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Volume
                                        </TableHead>
                                        <TableHead className="pr-5">
                                            Of daily norm
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {[...selected.days]
                                        .reverse()
                                        .filter(
                                            (d) =>
                                                d.approved > 0 ||
                                                d.declined > 0 ||
                                                d.date === day,
                                        )
                                        .map((d) => (
                                            <TableRow
                                                key={d.date}
                                                className={cn(
                                                    d.date === day &&
                                                        'bg-accent/50',
                                                )}
                                            >
                                                <TableCell className="pl-5">
                                                    {formatDate(d.date)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {d.approved}
                                                </TableCell>
                                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                                    {d.declined}
                                                </TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">
                                                    {formatMoney(
                                                        d.volume,
                                                        selected.terminal
                                                            .currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="pr-5">
                                                    <Meter
                                                        goal
                                                        percent={d.norm_percent}
                                                        empty="no norm"
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
            </PageBody>
        </>
    );
}

VolumesIndex.layout = {
    breadcrumbs: [{ title: 'Volumes', href: admin.volumes.index() }],
};
