import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge } from '@/components/admin/status-badge';
import { MerchantSwitcher, TxLink } from '@/components/portal/portal';
import type {
    PortalSettlement,
    PortalShared,
} from '@/components/portal/portal';
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
import portal from '@/routes/portal';
import type { Paginated } from '@/types';

type Props = PortalShared & { settlements: Paginated<PortalSettlement> };

export default function PortalSettlements(props: Props) {
    const { settlements } = props;

    return (
        <>
            <Head title="Settlements" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Settlements
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Payouts to your wallet. Each statement lists the daily
                        reports it pays and the conversion rate used.
                    </p>
                </div>
                <MerchantSwitcher
                    shared={props}
                    url={portal.settlements.url()}
                />
            </div>

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                {settlements.data.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                        No settlements yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">
                                    Statement
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Wallet
                                </TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Transaction
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="w-24 pr-5" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {settlements.data.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell className="pl-5">
                                        <span className="font-medium">
                                            {s.number}
                                        </span>
                                        {props.merchants.length > 1 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {s.merchant}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            name={s.status_label}
                                            color={
                                                s.status === 'settled'
                                                    ? 'emerald'
                                                    : 'blue'
                                            }
                                        />
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDate(
                                                s.settled_at ?? s.approved_at,
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell className="hidden text-sm md:table-cell">
                                        {s.wallet ?? '—'}
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <TxLink settlement={s} />
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatMoney(s.total_payout)}{' '}
                                        {s.payout_currency}
                                    </TableCell>
                                    <TableCell className="pr-5 text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <a
                                                href={portal.settlements.pdf.url(
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
                <Pagination page={settlements} noun="settlements" />
            </section>
        </>
    );
}
