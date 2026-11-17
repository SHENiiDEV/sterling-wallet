import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Pagination } from '@/components/admin/pagination';
import { PortalFilters } from '@/components/portal/portal';
import type { PortalShared } from '@/components/portal/portal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import portal from '@/routes/portal';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    report_date: string;
    type: 'refund' | 'chargeback' | 'collab';
    amount: string;
    currency: string;
    merchant: string | null;
    mid: string | null;
    time: string | null;
    card: string;
    payment_id: string;
    reference: string;
    sp_id: string;
    arn: string;
    reason: string;
};

type Props = PortalShared & {
    from: string;
    to: string;
    type: Row['type'] | null;
    totals: {
        type: Row['type'];
        currency: string;
        count: number;
        amount: string;
    }[];
    rows: Paginated<Row>;
};

const ALL = 'all';

const LABEL: Record<Row['type'], string> = {
    refund: 'Refund',
    chargeback: 'Chargeback',
    collab: 'Collaboration case',
};

const TONE: Record<Row['type'], string> = {
    refund: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    chargeback: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    collab: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
};

export default function PortalRefunds(props: Props) {
    const { rows, from, to, type, totals } = props;

    const query = (next: Record<string, string | null> = {}) =>
        Object.fromEntries(
            Object.entries({
                from,
                to,
                type,
                company: props.portal.company
                    ? String(props.portal.company.id)
                    : null,
                merchant: props.portal.merchant,
                ...next,
            }).filter(([, v]) => v),
        ) as Record<string, string>;

    const go = (next: Record<string, string | null>) =>
        router.get(portal.refunds.url(), query(next), {
            preserveScroll: true,
            replace: true,
        });

    const csv = portal.refunds.url({ query: { ...query(), format: 'csv' } });

    return (
        <>
            <Head title="Refunds & chargebacks" />
            <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Refunds & chargebacks
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Refunds, chargebacks and collaboration cases with the
                        ids to find the payment by. They appear once the
                        acquirer has reported them (refunds usually the next
                        day).
                    </p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Input
                        type="date"
                        aria-label="From"
                        className="bg-background sm:w-40"
                        value={from}
                        onChange={(e) => go({ from: e.target.value || null })}
                    />
                    <Input
                        type="date"
                        aria-label="To"
                        className="bg-background sm:w-40"
                        value={to}
                        onChange={(e) => go({ to: e.target.value || null })}
                    />
                    <Select
                        value={type ?? ALL}
                        onValueChange={(v) =>
                            go({ type: v === ALL ? null : v })
                        }
                    >
                        <SelectTrigger className="bg-background sm:w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
                            <SelectItem value="refund">Refunds</SelectItem>
                            <SelectItem value="chargeback">
                                Chargebacks
                            </SelectItem>
                            <SelectItem value="collab">
                                Collaboration cases
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <PortalFilters
                        shared={props}
                        url={portal.refunds.url()}
                        extra={{ from, to, type }}
                    />
                    <Button variant="outline" asChild>
                        <a href={csv}>
                            <Download />
                            CSV
                        </a>
                    </Button>
                </div>
            </div>

            {totals.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-3">
                    {totals.map((t) => (
                        <div
                            key={`${t.type}-${t.currency}`}
                            className="rounded-xl border bg-card p-4 shadow-xs"
                        >
                            <div className="text-xs text-muted-foreground">
                                {LABEL[t.type]}s · {formatDate(from)} –{' '}
                                {formatDate(to)}
                            </div>
                            <div className="text-lg font-semibold tabular-nums">
                                {formatMoney(t.amount, t.currency)}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {t.count} {t.count === 1 ? 'item' : 'items'}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                {rows.data.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                        No refunds or chargebacks in this period.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">
                                    Report day
                                </TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="hidden md:table-cell">
                                    MID
                                </TableHead>
                                <TableHead className="hidden lg:table-cell">
                                    Payment time (UTC)
                                </TableHead>
                                <TableHead>Card</TableHead>
                                <TableHead>Payment ID</TableHead>
                                <TableHead className="hidden xl:table-cell">
                                    sp_id
                                </TableHead>
                                <TableHead className="hidden xl:table-cell">
                                    Reference
                                </TableHead>
                                <TableHead className="hidden pr-5 lg:table-cell">
                                    Reason
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.data.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="pl-5 whitespace-nowrap">
                                        {formatDate(r.report_date)}
                                    </TableCell>
                                    <TableCell>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${TONE[r.type]}`}
                                        >
                                            {LABEL[r.type]}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        −{formatMoney(r.amount, r.currency)}
                                    </TableCell>
                                    <TableCell className="hidden font-mono text-xs md:table-cell">
                                        {r.mid ?? '—'}
                                    </TableCell>
                                    <TableCell className="hidden text-sm whitespace-nowrap lg:table-cell">
                                        {formatDateTime(r.time)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {r.card || '—'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs break-all">
                                        {r.payment_id}
                                    </TableCell>
                                    <TableCell className="hidden font-mono text-xs break-all xl:table-cell">
                                        {r.sp_id || '—'}
                                    </TableCell>
                                    <TableCell className="hidden font-mono text-xs xl:table-cell">
                                        {r.reference || '—'}
                                    </TableCell>
                                    <TableCell className="hidden pr-5 text-xs lg:table-cell">
                                        {r.reason || '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={rows} noun="items" />
            </section>
        </>
    );
}
