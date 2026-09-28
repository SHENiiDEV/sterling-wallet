import { Head } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import {
    DailyCharts,
    MerchantTable,
    PairTable,
    PeriodPicker,
    ProfitKpis,
} from '@/components/admin/profit/profit-widgets';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type {
    Period,
    ProfitByCurrency,
    ProfitByMerchant,
    ProfitByPair,
    ProfitPoint,
    ProfitSummary,
} from '@/types';

type Group = {
    currency: string;
    operations: number;
    amount: number;
    [key: string]: string | number;
};

type Props = {
    period: Period;
    previousPeriod: Period;
    baseCurrency: string;
    summary: ProfitSummary;
    previous: ProfitSummary;
    daily: ProfitPoint[];
    byCurrency: ProfitByCurrency[];
    byPair: ProfitByPair[];
    byMerchant: ProfitByMerchant[];
    breakdowns: {
        schemes: Group[];
        regions: Group[];
        countries: Group[];
        issuers: Group[];
        decline_reasons: Group[];
        conversion: {
            pair: string;
            approved: number;
            declined: number;
            rate: number | null;
        }[];
    };
};

function Breakdown({
    title,
    rows,
    keyName,
    empty,
    format = (v) => v,
}: {
    title: string;
    rows: Group[];
    keyName: string;
    empty: string;
    format?: (value: string) => string;
}) {
    const total = rows.reduce((sum, r) => sum + r.operations, 0);

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="border-b px-5 py-3.5">
                <h2 className="text-sm font-semibold">{title}</h2>
            </header>
            {rows.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead />
                            <TableHead className="text-right">
                                Operations
                            </TableHead>
                            <TableHead className="text-right">Share</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={`${row[keyName]}-${row.currency}`}>
                                <TableCell className="font-medium">
                                    {format(String(row[keyName]))}{' '}
                                    <span className="text-xs font-normal text-muted-foreground">
                                        {row.currency}
                                    </span>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.operations}
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                    {total
                                        ? `${((row.operations / total) * 100).toFixed(1)}%`
                                        : '—'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.amount, row.currency)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

export default function ProfitIndex({
    period,
    baseCurrency,
    summary,
    previous,
    daily,
    byPair,
    byMerchant,
    breakdowns,
}: Props) {
    return (
        <>
            <Head title="Providers & profit" />
            <PageBody>
                <PageHeader
                    title="Providers & profit"
                    description={`Completed daily reports only, converted to ${baseCurrency} at each report's frozen rate. Card and flow breakdowns come from operations of the same period.`}
                />
                <PeriodPicker period={period} url={admin.profit.index.url()} />

                <ProfitKpis
                    summary={summary}
                    previous={previous}
                    currency={baseCurrency}
                />
                <DailyCharts daily={daily} currency={baseCurrency} />
                <PairTable rows={byPair} currency={baseCurrency} />

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="text-sm font-semibold">
                            Approval rate per pair
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Successful vs declined attempts at the gateway.
                        </p>
                    </header>
                    {breakdowns.conversion.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No gateway operations in this period.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Pair</TableHead>
                                    <TableHead className="text-right">
                                        Approved
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Declined
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Approval rate
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {breakdowns.conversion.map((row) => (
                                    <TableRow key={row.pair}>
                                        <TableCell className="font-medium">
                                            {row.pair}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.approved}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.declined}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {row.rate === null
                                                ? '—'
                                                : `${row.rate}%`}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Breakdown
                        title="Card schemes (sales)"
                        rows={breakdowns.schemes}
                        keyName="scheme"
                        empty="No sales in this period."
                        format={(v) =>
                            v === 'visa'
                                ? 'Visa'
                                : v === 'mastercard'
                                  ? 'Mastercard'
                                  : v
                        }
                    />
                    <Breakdown
                        title="EU / non-EU (sales)"
                        rows={breakdowns.regions}
                        keyName="region"
                        empty="No sales in this period."
                        format={(v) =>
                            v === 'eu' ? 'EU' : v === 'non_eu' ? 'Non-EU' : v
                        }
                    />
                    <Breakdown
                        title="Issuer countries (sales)"
                        rows={breakdowns.countries}
                        keyName="country"
                        empty="No sales in this period."
                    />
                    <Breakdown
                        title="Issuing banks (sales)"
                        rows={breakdowns.issuers}
                        keyName="issuer"
                        empty="Reports in this period have no issuer column."
                    />
                    <Breakdown
                        title="Decline reasons"
                        rows={breakdowns.decline_reasons}
                        keyName="reason"
                        empty="No declines in this period."
                    />
                </div>

                <MerchantTable rows={byMerchant} currency={baseCurrency} />
            </PageBody>
        </>
    );
}

ProfitIndex.layout = {
    breadcrumbs: [{ title: 'Providers & profit', href: admin.profit.index() }],
};
