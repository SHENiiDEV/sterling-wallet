import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Pagination } from '@/components/admin/pagination';
import { PortalFilters, ReportFiles } from '@/components/portal/portal';
import type { PortalReport, PortalShared } from '@/components/portal/portal';
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
import portal from '@/routes/portal';
import type { Paginated } from '@/types';

type Money = Record<string, string>;

type ClientDay = {
    date: string;
    completed: number;
    waiting: number;
    payout: Money;
    companies: { id: number; name: string; reports: number; payout: Money }[];
};

type Props = PortalShared & {
    month: string | null;
    reports: Paginated<PortalReport>;
    /** Only for a login of a whole client: the consolidated daily statements. */
    clientDays: { name: string; month: string; days: ClientDay[] } | null;
};

function payoutText(payout: Money): string {
    const entries = Object.entries(payout);

    return entries.length === 0
        ? '—'
        : entries
              .map(([currency, amount]) => formatMoney(amount, currency))
              .join(' · ');
}

export default function PortalReports(props: Props) {
    const { reports, month } = props;
    const filter = (next: Record<string, string | null>) =>
        router.get(
            portal.reports.url(),
            Object.fromEntries(
                Object.entries({
                    month,
                    company: props.portal.company
                        ? String(props.portal.company.id)
                        : null,
                    merchant: props.portal.merchant,
                    ...next,
                }).filter(([, v]) => v),
            ),
            { preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Daily reports" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Daily reports
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        One report per MID and processing day: PDF statement,
                        XLSX summary and CSV of all transactions.
                    </p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        type="month"
                        aria-label="Month"
                        className="bg-background sm:w-44"
                        value={month ?? ''}
                        onChange={(e) =>
                            filter({ month: e.target.value || null })
                        }
                    />
                    <PortalFilters
                        shared={props}
                        url={portal.reports.url()}
                        extra={{ month }}
                    />
                </div>
            </div>

            {props.clientDays && (
                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">
                            Daily statements · {props.clientDays.name}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            One statement per day for all companies, or for one
                            company. Month: {props.clientDays.month}.
                        </p>
                    </header>
                    {props.clientDays.days.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No completed days in this month.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {props.clientDays.days.map((day) => (
                                <li key={day.date} className="px-5 py-3">
                                    <div className="flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">
                                                {formatDate(day.date)}
                                            </div>
                                            <div className="text-xs text-muted-foreground tabular-nums">
                                                Net payout{' '}
                                                {payoutText(day.payout)}
                                                {day.waiting > 0 &&
                                                    ` · ${day.waiting} still waiting for data`}
                                            </div>
                                        </div>
                                        {(['pdf', 'xlsx'] as const).map(
                                            (format) => (
                                                <Button
                                                    key={format}
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={`${portal.reports.client.url()}?date=${day.date}&format=${format}`}
                                                    >
                                                        <Download />
                                                        All companies{' '}
                                                        {format.toUpperCase()}
                                                    </a>
                                                </Button>
                                            ),
                                        )}
                                    </div>
                                    {day.companies.length > 1 && (
                                        <ul className="mt-2 grid gap-1 text-sm">
                                            {day.companies.map((company) => (
                                                <li
                                                    key={company.id}
                                                    className="flex flex-wrap items-center gap-3"
                                                >
                                                    <span className="min-w-0 flex-1 text-muted-foreground">
                                                        {company.name}
                                                    </span>
                                                    <span className="tabular-nums">
                                                        {payoutText(
                                                            company.payout,
                                                        )}
                                                    </span>
                                                    {(
                                                        ['pdf', 'xlsx'] as const
                                                    ).map((format) => (
                                                        <Button
                                                            key={format}
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={`${portal.reports.client.url()}?date=${day.date}&format=${format}&company=${company.id}`}
                                                            >
                                                                <Download />
                                                                {format.toUpperCase()}
                                                            </a>
                                                        </Button>
                                                    ))}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                {reports.data.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                        No reports for this selection.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Date</TableHead>
                                <TableHead>MID</TableHead>
                                <TableHead className="text-right">
                                    Sales
                                </TableHead>
                                <TableHead className="hidden text-right md:table-cell">
                                    Refunds + CB
                                </TableHead>
                                <TableHead className="hidden text-right md:table-cell">
                                    Fees
                                </TableHead>
                                <TableHead className="hidden text-right lg:table-cell">
                                    Reserve
                                </TableHead>
                                <TableHead className="text-right">
                                    Net payout
                                </TableHead>
                                <TableHead className="pr-5 text-right">
                                    Files
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reports.data.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="pl-5 whitespace-nowrap">
                                        {formatDate(r.report_date)}
                                        {r.period_from !== r.period_to && (
                                            <span className="block text-xs text-muted-foreground">
                                                from {formatDate(r.period_from)}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {r.mid}
                                        {props.portal.merchants.length > 1 ||
                                            (props.portal.companies.length >
                                                1 && (
                                                <span className="block font-sans text-muted-foreground">
                                                    {r.merchant}
                                                    {r.website &&
                                                        ` · ${r.website}`}
                                                    {props.portal.companies
                                                        .length > 1 &&
                                                        r.company &&
                                                        ` · ${r.company}`}
                                                </span>
                                            ))}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(r.turnover, r.currency)}
                                        <span className="block text-xs text-muted-foreground">
                                            {r.sales_count} sales
                                        </span>
                                    </TableCell>
                                    <TableCell className="hidden text-right tabular-nums md:table-cell">
                                        {formatMoney(
                                            -Number(r.refunds),
                                            r.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-right tabular-nums md:table-cell">
                                        {formatMoney(
                                            -Number(r.fees),
                                            r.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-right tabular-nums lg:table-cell">
                                        {formatMoney(
                                            -Number(r.reserve),
                                            r.currency,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatMoney(r.net_payout, r.currency)}
                                    </TableCell>
                                    <TableCell className="pr-5">
                                        <ReportFiles report={r} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={reports} noun="reports" />
            </section>
        </>
    );
}
