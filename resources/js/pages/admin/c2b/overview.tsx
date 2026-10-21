import { Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PeriodPicker } from '@/components/admin/profit/profit-widgets';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type { Period } from '@/types';

type Props = {
    product: { value: string; label: string };
    period: Period;
    baseCurrency: string;
    totals: {
        currency: string;
        reports: number;
        sales: number;
        turnover: number;
        refunds: number;
        fees: number;
        payout: number;
    }[];
    daily: { date: string; turnover: number }[];
    merchants: {
        public_id: string;
        name: string;
        currency: string;
        turnover: number;
        fees: number;
    }[];
    unsettled: { currency: string; reports: number; payout: number }[];
    recent: {
        id: number;
        reference: string | null;
        merchant: string | null;
        amount: string;
        currency: string;
        at: string | null;
    }[];
    reportAlerts: { key: string; label: string; count: number }[];
};

export default function C2bOverview({
    product,
    period,
    baseCurrency,
    totals,
    daily,
    merchants,
    unsettled,
    recent,
    reportAlerts,
}: Props) {
    return (
        <>
            <Head title={product.label} />
            <PageBody>
                <PageHeader
                    title={product.label}
                    description="Volume, what merchants pay and what is waiting to be paid out."
                />
                {reportAlerts.length > 0 && (
                    <ul className="grid gap-2">
                        {reportAlerts.map((alert) => (
                            <li
                                key={alert.key}
                                className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm"
                            >
                                <AlertTriangle className="size-4 text-amber-600" />
                                <span className="font-medium">
                                    {alert.count}
                                </span>{' '}
                                {alert.label}
                            </li>
                        ))}
                    </ul>
                )}
                <PeriodPicker period={period} url={admin.dashboard.url()} />

                {totals.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        No completed reports in this period.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {totals.map((t) => (
                            <div
                                key={t.currency}
                                className="rounded-xl border bg-card p-5 shadow-xs"
                            >
                                <div className="flex items-center justify-between">
                                    <CurrencyBadge currency={t.currency} />
                                    <span className="text-xs text-muted-foreground">
                                        {t.reports} reports · {t.sales} sales
                                    </span>
                                </div>
                                <dl className="mt-3 grid grid-cols-2 gap-y-1.5 text-sm">
                                    <dt className="text-muted-foreground">
                                        Turnover
                                    </dt>
                                    <dd className="text-right font-medium tabular-nums">
                                        {formatMoney(t.turnover, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Refunds & chargebacks
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.refunds, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Merchant fees
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.fees, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Net payout
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.payout, t.currency)}
                                    </dd>
                                </dl>
                            </div>
                        ))}
                    </div>
                )}

                <section className="rounded-xl border bg-card p-5 shadow-xs">
                    <h2 className="mb-3 text-sm font-semibold">
                        Turnover per report day · {baseCurrency}
                    </h2>
                    <BarChart
                        label="Turnover per report day"
                        points={daily.map((d) => ({
                            date: d.date,
                            value: d.turnover,
                        }))}
                        format={(v) => formatMoney(v, baseCurrency, 0)}
                    />
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center justify-between border-b px-5 py-3.5">
                            <h2 className="text-sm font-semibold">
                                Waiting to be settled
                            </h2>
                            <Link
                                href={admin.settlements.index()}
                                className="text-xs text-primary hover:underline"
                            >
                                Settlements
                            </Link>
                        </header>
                        {unsettled.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-muted-foreground">
                                Nothing is waiting.
                            </p>
                        ) : (
                            <Table>
                                <TableBody>
                                    {unsettled.map((u) => (
                                        <TableRow key={u.currency}>
                                            <TableCell>
                                                <CurrencyBadge
                                                    currency={u.currency}
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {u.reports} report(s)
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    u.payout,
                                                    u.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </section>

                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center justify-between border-b px-5 py-3.5">
                            <h2 className="text-sm font-semibold">
                                Top merchants
                            </h2>
                            <Link
                                href={admin.merchants.folders()}
                                className="text-xs text-primary hover:underline"
                            >
                                Merchants
                            </Link>
                        </header>
                        {merchants.length === 0 ? (
                            <p className="px-5 py-6 text-sm text-muted-foreground">
                                No volume in this period.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Merchant</TableHead>
                                        <TableHead className="text-right">
                                            Turnover
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Fees
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {merchants.map((m) => (
                                        <TableRow
                                            key={`${m.public_id}-${m.currency}`}
                                        >
                                            <TableCell>
                                                <Link
                                                    href={admin.merchants.show(
                                                        m.public_id,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {m.name}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    m.turnover,
                                                    m.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    m.fees,
                                                    m.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </section>
                </div>

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="flex items-center justify-between border-b px-5 py-3.5">
                        <h2 className="text-sm font-semibold">
                            Latest transfers
                        </h2>
                        <Link
                            href={admin.operations.index()}
                            className="text-xs text-primary hover:underline"
                        >
                            All operations
                        </Link>
                    </header>
                    {recent.length === 0 ? (
                        <p className="px-5 py-6 text-sm text-muted-foreground">
                            No operations yet.
                        </p>
                    ) : (
                        <Table>
                            <TableBody>
                                {recent.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell className="text-muted-foreground">
                                            {o.at ? formatDateTime(o.at) : '—'}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={admin.operations.show(
                                                    o.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {o.reference ?? '—'}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{o.merchant}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(o.amount, o.currency)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </PageBody>
        </>
    );
}

C2bOverview.layout = {
    breadcrumbs: [{ title: 'Overview', href: admin.dashboard() }],
};
