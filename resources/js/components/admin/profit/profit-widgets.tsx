import { Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { BarChart } from '@/components/admin/bar-chart';
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
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type {
    Period,
    ProfitByMerchant,
    ProfitByPair,
    ProfitPoint,
    ProfitProduct,
    ProfitSummary,
} from '@/types';

const iso = (d: Date) => d.toISOString().slice(0, 10);

function presets(): { label: string; from: string; to: string }[] {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    const days = (n: number) => {
        const d = new Date(now);
        d.setDate(d.getDate() - n);

        return iso(d);
    };

    return [
        { label: 'This month', from: iso(new Date(y, m, 1, 12)), to: iso(now) },
        {
            label: 'Last month',
            from: iso(new Date(y, m - 1, 1, 12)),
            to: iso(new Date(y, m, 0, 12)),
        },
        { label: '30 days', from: days(29), to: iso(now) },
        { label: '90 days', from: days(89), to: iso(now) },
        { label: 'This year', from: iso(new Date(y, 0, 1, 12)), to: iso(now) },
    ];
}

/**
 * Date range control: presets plus from/to, one row above the charts.
 */
export function PeriodPicker({
    period,
    url,
    product,
}: {
    period: Period;
    url: string;
    product?: string | null;
}) {
    const go = (from: string, to: string) =>
        router.get(
            url,
            { from, to, ...(product ? { product } : {}) },
            { preserveScroll: true, replace: true },
        );

    return (
        <div className="flex flex-wrap items-center gap-2">
            {presets().map((preset) => {
                const active =
                    preset.from === period.from && preset.to === period.to;

                return (
                    <Button
                        key={preset.label}
                        size="sm"
                        variant={active ? 'default' : 'outline'}
                        onClick={() => go(preset.from, preset.to)}
                    >
                        {preset.label}
                    </Button>
                );
            })}
            <Input
                type="date"
                value={period.from}
                max={period.to}
                onChange={(e) =>
                    e.target.value && go(e.target.value, period.to)
                }
                className="h-8 w-38"
                aria-label="From"
            />
            <span className="text-muted-foreground">—</span>
            <Input
                type="date"
                value={period.to}
                min={period.from}
                onChange={(e) =>
                    e.target.value && go(period.from, e.target.value)
                }
                className="h-8 w-38"
                aria-label="To"
            />
        </div>
    );
}

function Delta({
    now,
    before,
    inverse = false,
}: {
    now: number;
    before: number;
    inverse?: boolean;
}) {
    if (before === 0) {
        return (
            <span className="text-xs text-muted-foreground">no prior data</span>
        );
    }

    const change = ((now - before) / Math.abs(before)) * 100;
    const good = inverse ? change <= 0 : change >= 0;
    const Icon = change >= 0 ? ArrowUpRight : ArrowDownRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 text-xs font-medium',
                good ? 'text-success' : 'text-destructive-foreground',
            )}
        >
            <Icon className="size-3.5" />
            {Math.abs(change).toFixed(1)}%
            <span className="font-normal text-muted-foreground">
                &nbsp;vs previous
            </span>
        </span>
    );
}

function Kpi({
    label,
    value,
    children,
}: {
    label: string;
    value: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-xl border bg-card p-5 shadow-xs">
            <p className="text-sm font-medium text-muted-foreground">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            <div className="mt-1 min-h-4">{children}</div>
        </div>
    );
}

export function ProfitKpis({
    summary,
    previous,
    currency,
}: {
    summary: ProfitSummary;
    previous: ProfitSummary;
    currency: string;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <Kpi
                label="Turnover"
                value={formatMoney(summary.turnover, currency)}
            >
                <Delta now={summary.turnover} before={previous.turnover} />
            </Kpi>
            <Kpi label="Revenue" value={formatMoney(summary.revenue, currency)}>
                <Delta now={summary.revenue} before={previous.revenue} />
            </Kpi>
            <Kpi
                label="Provider cost"
                value={formatMoney(summary.cost, currency)}
            >
                <Delta now={summary.cost} before={previous.cost} inverse />
            </Kpi>
            <Kpi
                label="Net profit"
                value={formatMoney(summary.net_profit, currency)}
            >
                <Delta now={summary.net_profit} before={previous.net_profit} />
            </Kpi>
            <Kpi
                label="Margin"
                value={summary.margin === null ? '—' : `${summary.margin}%`}
            >
                <span className="text-xs text-muted-foreground">
                    {summary.reports} reports · {summary.sales} sales
                </span>
            </Kpi>
        </div>
    );
}

export function DailyCharts({
    daily,
    currency,
}: {
    daily: ProfitPoint[];
    currency: string;
}) {
    const money = (v: number) => formatMoney(v, currency, 0);

    return (
        <div className="grid gap-6 xl:grid-cols-2">
            <section className="rounded-xl border bg-card p-5 shadow-xs">
                <h2 className="mb-3 text-sm font-semibold">
                    Net profit per report day
                </h2>
                <BarChart
                    label="Net profit per report day"
                    points={daily.map((d) => ({
                        date: d.date,
                        value: d.net_profit,
                    }))}
                    format={money}
                />
            </section>
            <section className="rounded-xl border bg-card p-5 shadow-xs">
                <h2 className="mb-3 text-sm font-semibold">
                    Turnover per report day
                </h2>
                <BarChart
                    label="Turnover per report day"
                    points={daily.map((d) => ({
                        date: d.date,
                        value: d.turnover,
                    }))}
                    format={money}
                />
            </section>
        </div>
    );
}

export function PairTable({
    rows,
    currency,
}: {
    rows: ProfitByPair[];
    currency: string;
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="border-b px-5 py-3.5">
                <h2 className="text-sm font-semibold">
                    Margin per provider pair
                </h2>
            </header>
            {rows.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    No completed reports in this period.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Acquirer ↔ gateway</TableHead>
                            <TableHead className="text-right">
                                Turnover
                            </TableHead>
                            <TableHead className="text-right">
                                Revenue
                            </TableHead>
                            <TableHead className="text-right">Cost</TableHead>
                            <TableHead className="text-right">
                                Net profit
                            </TableHead>
                            <TableHead className="text-right">Margin</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.pair}>
                                <TableCell>
                                    <div className="font-medium">
                                        {row.pair}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {row.mids} MID(s)
                                    </div>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.turnover, currency)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.revenue, currency)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.cost, currency)}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {formatMoney(row.net_profit, currency)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.margin === null
                                        ? '—'
                                        : `${row.margin}%`}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

export function MerchantTable({
    rows,
    currency,
    title = 'Merchants',
}: {
    rows: ProfitByMerchant[];
    currency: string;
    title?: string;
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="border-b px-5 py-3.5">
                <h2 className="text-sm font-semibold">{title}</h2>
            </header>
            {rows.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    No completed reports in this period.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Merchant</TableHead>
                            <TableHead className="text-right">
                                Turnover
                            </TableHead>
                            <TableHead className="text-right">
                                Revenue
                            </TableHead>
                            <TableHead className="text-right">
                                Net profit
                            </TableHead>
                            <TableHead className="text-right">Margin</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.public_id}>
                                <TableCell>
                                    <Link
                                        href={admin.merchants.show(
                                            row.public_id,
                                        )}
                                        className="font-medium hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {row.reports} report(s)
                                    </div>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.turnover, currency)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.revenue, currency)}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {formatMoney(row.net_profit, currency)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.margin === null
                                        ? '—'
                                        : `${row.margin}%`}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

/**
 * Every product side by side; a card selects it for the whole page, "All" clears it.
 */
export function ProductSplit({
    products,
    product,
    period,
    url,
    currency,
}: {
    products: ProfitProduct[];
    product: string | null;
    period: Period;
    url: string;
    currency: string;
}) {
    const pick = (key: string | null) =>
        router.get(
            url,
            { ...period, ...(key ? { product: key } : {}) },
            { preserveScroll: true, replace: true },
        );

    return (
        <div className="grid gap-3 md:grid-cols-3">
            <button
                type="button"
                onClick={() => pick(null)}
                className={cn(
                    'rounded-xl border bg-card p-4 text-left shadow-xs transition hover:border-primary/40',
                    product === null && 'border-primary ring-1 ring-primary',
                )}
            >
                <p className="text-sm font-semibold">All products</p>
                <p className="mt-1 text-xs text-muted-foreground">
                    Card acquiring and C2B together
                </p>
            </button>
            {products.map((p) => (
                <button
                    key={p.key}
                    type="button"
                    onClick={() => pick(p.key)}
                    className={cn(
                        'rounded-xl border bg-card p-4 text-left shadow-xs transition hover:border-primary/40',
                        product === p.key &&
                            'border-primary ring-1 ring-primary',
                    )}
                >
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-semibold">{p.label}</p>
                        <span className="text-xs text-muted-foreground">
                            {p.summary.reports} reports
                        </span>
                    </div>
                    <dl className="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                        <dt className="text-muted-foreground">Turnover</dt>
                        <dd className="text-right tabular-nums">
                            {formatMoney(p.summary.turnover, currency)}
                        </dd>
                        <dt className="text-muted-foreground">Revenue</dt>
                        <dd className="text-right tabular-nums">
                            {formatMoney(p.summary.revenue, currency)}
                        </dd>
                        <dt className="text-muted-foreground">Net profit</dt>
                        <dd className="text-right font-medium tabular-nums">
                            {formatMoney(p.summary.net_profit, currency)}
                        </dd>
                    </dl>
                    <div className="mt-1 flex items-center justify-between">
                        <Delta
                            now={p.summary.net_profit}
                            before={p.previous.net_profit}
                        />
                        <span className="text-xs text-muted-foreground">
                            {p.summary.margin === null
                                ? '—'
                                : `${p.summary.margin}% margin`}
                        </span>
                    </div>
                </button>
            ))}
        </div>
    );
}
