import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge } from '@/components/admin/status-badge';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
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
import { settlementColors } from '@/components/admin/settlements';
import type { SettlementItem } from '@/components/admin/settlements';
import type { Option, Paginated } from '@/types';

type Props = {
    settlements: Paginated<SettlementItem>;
    pending: {
        public_id: string;
        name: string;
        currency: string;
        reports: number;
        payout: number;
    }[];
    filters: { status?: string; merchant?: string };
    statuses: Option[];
    merchants: { public_id: string; name: string }[];
};

const ALL = 'all';

function NewSettlementDialog({
    merchants,
    preset,
    onClose,
}: {
    merchants: Props['merchants'];
    preset: string | null;
    onClose: () => void;
}) {
    const form = useForm({ merchant: preset ?? merchants[0]?.public_id ?? '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(admin.settlements.store.url());
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>New settlement</DialogTitle>
                        <DialogDescription>
                            The draft picks up every completed report and
                            released reserve of the merchant that is not paid
                            yet. You can remove lines and add adjustments before
                            approving.
                        </DialogDescription>
                    </DialogHeader>
                    <Field label="Merchant" error={form.errors.merchant}>
                        <Select
                            value={form.data.merchant}
                            onValueChange={(v) => form.setData('merchant', v)}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose…" />
                            </SelectTrigger>
                            <SelectContent>
                                {merchants.map((m) => (
                                    <SelectItem
                                        key={m.public_id}
                                        value={m.public_id}
                                    >
                                        {m.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.merchant}
                        >
                            {form.processing && <Spinner />}
                            Create draft
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SettlementsIndex({
    settlements,
    pending,
    filters,
    statuses,
    merchants,
}: Props) {
    const [creating, setCreating] = useState<string | null | false>(false);
    const apply = (next: Props['filters']) =>
        router.get(
            admin.settlements.index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Settlements" />
            <PageBody>
                <PageHeader
                    title="Settlements"
                    description="Payouts to merchants in USDC. One settlement can cover several days and currencies; draft → approved → settled."
                    actions={
                        <Button onClick={() => setCreating(null)}>
                            <Plus />
                            New settlement
                        </Button>
                    }
                />

                {pending.length > 0 && (
                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="border-b px-5 py-3.5">
                            <h2 className="text-sm font-semibold">
                                Waiting to be settled
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Completed reports not in any draft, approved or
                                settled payout.
                            </p>
                        </header>
                        <Table>
                            <TableBody>
                                {pending.map((row) => (
                                    <TableRow
                                        key={`${row.public_id}-${row.currency}`}
                                    >
                                        <TableCell className="font-medium">
                                            {row.name}
                                        </TableCell>
                                        <TableCell>
                                            <CurrencyBadge
                                                currency={row.currency}
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {row.reports} report(s)
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(
                                                row.payout,
                                                row.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="w-0 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setCreating(row.public_id)
                                                }
                                            >
                                                Settle
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3 md:flex-row">
                        <Select
                            value={filters.status ?? ALL}
                            onValueChange={(v) =>
                                apply({ status: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any status</SelectItem>
                                {statuses.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        {s.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.merchant ?? ALL}
                            onValueChange={(v) =>
                                apply({ merchant: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-60">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All merchants
                                </SelectItem>
                                {merchants.map((m) => (
                                    <SelectItem
                                        key={m.public_id}
                                        value={m.public_id}
                                    >
                                        {m.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {settlements.data.length === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            title="No settlements"
                            description="Create one from a merchant's completed reports."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Merchant</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Lines</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead className="text-right">
                                        Total
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {settlements.data.map((s) => (
                                    <TableRow
                                        key={s.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                admin.settlements.show.url(
                                                    s.id,
                                                ),
                                            )
                                        }
                                    >
                                        <TableCell>
                                            <Link
                                                href={admin.settlements.show(
                                                    s.id,
                                                )}
                                                className="font-mono text-xs font-medium hover:underline"
                                            >
                                                {s.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{s.merchant.name}</TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                name={s.status_label}
                                                color={
                                                    settlementColors[
                                                        s.status
                                                    ] ?? 'slate'
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {s.lines_count}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(s.created_at)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(s.total_payout)}{' '}
                                            {s.payout_currency}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={settlements} noun="settlements" />
                </div>
            </PageBody>
            {creating !== false && (
                <NewSettlementDialog
                    merchants={merchants}
                    preset={creating}
                    onClose={() => setCreating(false)}
                />
            )}
        </>
    );
}

SettlementsIndex.layout = {
    breadcrumbs: [{ title: 'Settlements', href: admin.settlements.index() }],
};
