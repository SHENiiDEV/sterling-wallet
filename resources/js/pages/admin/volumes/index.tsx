import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
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
    projected: number;
};

type Props = {
    month: string;
    previous: string;
    next: string | null;
    rows: Terminal[];
    totals: {
        currency: string;
        terminals: number;
        approved: number;
        volume: number;
    }[];
    selected: {
        terminal: Terminal;
        days: {
            date: string;
            approved: number;
            declined: number;
            volume: number;
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

/** Share of the monthly limit used: amber from 80 %, red from 100 %. */
function Usage({ percent }: { percent: number | null }) {
    if (percent === null) {
        return <span className="text-muted-foreground">no limit</span>;
    }

    return (
        <div className="flex items-center gap-2">
            <div className="h-1.5 w-24 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full',
                        percent >= 100
                            ? 'bg-rose-500'
                            : percent >= 80
                              ? 'bg-amber-500'
                              : 'bg-emerald-500',
                    )}
                    style={{ width: `${Math.min(100, percent)}%` }}
                />
            </div>
            <span className="text-xs tabular-nums">{percent}%</span>
        </div>
    );
}

export default function VolumesIndex({
    month,
    previous,
    next,
    rows,
    totals,
    selected,
}: Props) {
    const go = (query: Record<string, string | number>) =>
        router.get(admin.volumes.index.url(), query, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <>
            <Head title="Volumes" />
            <PageBody>
                <PageHeader
                    title="Volumes"
                    description="Successful gateway volume per terminal (MID), by day and month, against the monthly processing limit."
                    actions={
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous month"
                                onClick={() => go({ month: previous })}
                            >
                                <ChevronLeft />
                            </Button>
                            <span className="min-w-36 text-center text-sm font-medium">
                                {monthLabel(month)}
                            </span>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Next month"
                                disabled={next === null}
                                onClick={() => next && go({ month: next })}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    }
                />

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
                                    {t.approved} successful transactions
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
                                        Approval
                                    </TableHead>
                                    <TableHead>Of monthly limit</TableHead>
                                    <TableHead className="pr-5 text-right">
                                        Month pace
                                    </TableHead>
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
                                        onClick={() => go({ month, mid: t.id })}
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
                                            {t.approval_rate === null
                                                ? '—'
                                                : `${t.approval_rate}%`}
                                        </TableCell>
                                        <TableCell>
                                            <Usage percent={t.used_percent} />
                                            {t.limit !== null && (
                                                <div className="text-xs text-muted-foreground">
                                                    of{' '}
                                                    {formatMoney(
                                                        t.limit,
                                                        t.currency,
                                                        0,
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="pr-5 text-right text-muted-foreground tabular-nums">
                                            {formatMoney(
                                                t.projected,
                                                t.currency,
                                                0,
                                            )}
                                        </TableCell>
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
                                        <TableHead className="text-right">
                                            Month to date
                                        </TableHead>
                                        <TableHead className="pr-5">
                                            Of limit
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {selected.days
                                        .filter(
                                            (d) =>
                                                d.approved > 0 ||
                                                d.declined > 0,
                                        )
                                        .map((d) => (
                                            <TableRow key={d.date}>
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
                                                <TableCell className="text-right tabular-nums">
                                                    {formatMoney(
                                                        d.cumulative,
                                                        selected.terminal
                                                            .currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="pr-5">
                                                    <Usage
                                                        percent={
                                                            d.cumulative_percent
                                                        }
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
