import { Link, router } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { StatusBadge } from '@/components/admin/status-badge';
import { Amounts } from '@/components/portal/portal';
import type { Amount } from '@/components/portal/portal';
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
import admin from '@/routes/admin';
import type { StatusColor } from '@/types';

export type MerchantOverview = {
    month: string;
    sales: number;
    turnover: Amount[];
    payout: Amount[];
    unpaid: Amount[];
    reserve: Amount[];
    last_payout: {
        number: string;
        amount: string;
        currency: string;
        date: string | null;
    } | null;
    daily: { date: string; value: number }[];
    base_currency: string;
};

export type MerchantReport = {
    id: number;
    report_date: string;
    mid: string;
    currency: string;
    status: string;
    status_label: string;
    sales_count: number | null;
    turnover: string | null;
    net_payout: string | null;
    net_profit: string | null;
    has_pdf: boolean;
};

export type MerchantSettlement = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    lines: number;
    total_payout: string;
    payout_currency: string;
    created_at: string | null;
    settled_at: string | null;
    tx_hash: string | null;
};

const reportColors: Record<string, StatusColor> = {
    completed: 'emerald',
    partial: 'amber',
    pending: 'slate',
    blocked: 'rose',
};

const settlementColors: Record<string, StatusColor> = {
    draft: 'amber',
    approved: 'blue',
    settled: 'emerald',
    cancelled: 'slate',
};

export function ReportsTab({ reports }: { reports: MerchantReport[] }) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="flex items-center justify-between border-b px-5 py-3.5">
                <div>
                    <h2 className="text-sm font-semibold">Daily reports</h2>
                    <p className="text-xs text-muted-foreground">
                        Latest 60 · profit is internal and never shown to the
                        merchant.
                    </p>
                </div>
                <Button size="sm" variant="outline" asChild>
                    <Link href={admin.reports.index()}>Report Center</Link>
                </Button>
            </header>
            {reports.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                    No daily reports yet.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-5">Date</TableHead>
                            <TableHead>MID</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Sales</TableHead>
                            <TableHead className="text-right">Payout</TableHead>
                            <TableHead className="hidden text-right md:table-cell">
                                Our profit
                            </TableHead>
                            <TableHead className="w-20 pr-5" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {reports.map((r) => (
                            <TableRow
                                key={r.id}
                                className="cursor-pointer"
                                onClick={() =>
                                    router.visit(admin.reports.show.url(r.id))
                                }
                            >
                                <TableCell className="pl-5 whitespace-nowrap">
                                    {formatDate(r.report_date)}
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {r.mid}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        name={r.status_label}
                                        color={
                                            reportColors[r.status] ?? 'slate'
                                        }
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.turnover !== null
                                        ? formatMoney(r.turnover, r.currency)
                                        : '—'}
                                    {r.sales_count !== null && (
                                        <span className="block text-xs text-muted-foreground">
                                            {r.sales_count} sales
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {r.net_payout !== null
                                        ? formatMoney(r.net_payout, r.currency)
                                        : '—'}
                                </TableCell>
                                <TableCell className="hidden text-right text-muted-foreground tabular-nums md:table-cell">
                                    {r.net_profit !== null
                                        ? formatMoney(r.net_profit, r.currency)
                                        : '—'}
                                </TableCell>
                                <TableCell
                                    className="pr-5 text-right"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    {r.has_pdf && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <a
                                                href={admin.reports.download.url(
                                                    {
                                                        report: r.id,
                                                        file: 'pdf',
                                                    },
                                                )}
                                            >
                                                <Download />
                                                PDF
                                            </a>
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

export function SettlementsTab({
    merchantId,
    settlements,
    unpaid,
}: {
    merchantId: string;
    settlements: MerchantSettlement[];
    unpaid: Amount[];
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                <div>
                    <h2 className="text-sm font-semibold">Settlements</h2>
                    <p className="text-xs text-muted-foreground">
                        Not paid out yet:{' '}
                        <Amounts
                            amounts={unpaid}
                            className="inline font-medium text-foreground [&>span]:inline [&>span+span]:before:content-['_+_']"
                        />
                    </p>
                </div>
                <Button
                    size="sm"
                    disabled={unpaid.length === 0}
                    onClick={() =>
                        router.post(admin.settlements.store.url(), {
                            merchant: merchantId,
                        })
                    }
                >
                    <Plus />
                    New settlement
                </Button>
            </header>
            {settlements.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                    No settlements yet.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-5">Number</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="hidden md:table-cell">
                                Lines
                            </TableHead>
                            <TableHead className="text-right">Payout</TableHead>
                            <TableHead className="w-20 pr-5" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {settlements.map((s) => (
                            <TableRow
                                key={s.id}
                                className="cursor-pointer"
                                onClick={() =>
                                    router.visit(
                                        admin.settlements.show.url(s.id),
                                    )
                                }
                            >
                                <TableCell className="pl-5">
                                    <span className="font-medium">
                                        {s.number}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {s.settled_at
                                            ? `Paid ${formatDate(s.settled_at)}`
                                            : `Created ${formatDate(s.created_at)}`}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        name={s.status_label}
                                        color={
                                            settlementColors[s.status] ??
                                            'slate'
                                        }
                                    />
                                </TableCell>
                                <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                    {s.lines}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {formatMoney(s.total_payout)}{' '}
                                    {s.payout_currency}
                                </TableCell>
                                <TableCell
                                    className="pr-5 text-right"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <Button size="sm" variant="ghost" asChild>
                                        <a
                                            href={admin.settlements.pdf.url(
                                                s.id,
                                            )}
                                        >
                                            <Download />
                                            PDF
                                        </a>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}
