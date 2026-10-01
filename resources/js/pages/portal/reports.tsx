import { Head, router } from '@inertiajs/react';
import { Pagination } from '@/components/admin/pagination';
import { MerchantSwitcher, ReportFiles } from '@/components/portal/portal';
import type { PortalReport, PortalShared } from '@/components/portal/portal';
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

type Props = PortalShared & {
    month: string | null;
    reports: Paginated<PortalReport>;
};

export default function PortalReports(props: Props) {
    const { reports, month } = props;
    const filter = (next: Record<string, string | null>) =>
        router.get(
            portal.reports.url(),
            Object.fromEntries(
                Object.entries({
                    month,
                    merchant: props.merchant,
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
                    <MerchantSwitcher
                        shared={props}
                        url={portal.reports.url()}
                        extra={{ month }}
                    />
                </div>
            </div>

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
                                        {props.merchants.length > 1 && (
                                            <span className="block font-sans text-muted-foreground">
                                                {r.merchant}
                                            </span>
                                        )}
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
