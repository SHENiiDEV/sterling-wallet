import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge } from '@/components/admin/status-badge';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import { settlementColors } from '@/components/admin/settlements';
import type { SettlementItem } from '@/components/admin/settlements';
import type { Option, Paginated } from '@/types';

type Props = {
    settlements: Paginated<SettlementItem>;
    pending: {
        public_id: string;
        name: string;
        product: string;
        currency: string;
        reports: number;
        payout: number;
        due_reports: number;
        due_payout: number;
        overdue_reports: number;
        next_due: string | null;
        scheduled: boolean;
    }[];
    pendingClients: {
        id: number;
        name: string;
        product: string;
        currency: string;
        reports: number;
        companies: number;
        payout: number;
        due_reports: number;
        due_payout: number;
        overdue_reports: number;
        next_due: string | null;
        scheduled: boolean;
    }[];
    clients: { id: number; name: string }[];
    payoutCurrencies: Option[];
    products: Option[];
    filters: { status?: string; merchant?: string; cancelled?: string };
    cancelledCount: number;
    statuses: Option[];
    merchants: { public_id: string; name: string }[];
};

type Target = (
    | { client: number; merchant?: undefined }
    | { merchant: string; client?: undefined }
) & { product?: string; scheduled?: boolean };

const ALL = 'all';

type DueRow = {
    reports: number;
    due_reports: number;
    due_payout: number;
    payout: number;
    overdue_reports: number;
    next_due: string | null;
    scheduled: boolean;
    currency: string;
};

/** What of the unpaid reports is due for payout today by the merchant's T+N. */
function DueInfo({ row }: { row: DueRow }) {
    if (!row.scheduled) {
        return <>{row.reports} report(s)</>;
    }

    return (
        <div className="grid gap-0.5">
            <span
                className={
                    row.due_reports > 0
                        ? 'font-medium text-emerald-600'
                        : 'text-muted-foreground'
                }
            >
                {row.due_reports} due
                {row.overdue_reports > 0 && (
                    <span className="ml-1 text-amber-600">
                        ({row.overdue_reports} overdue)
                    </span>
                )}
            </span>
            {row.reports > row.due_reports && (
                <span className="text-xs text-muted-foreground">
                    {row.reports - row.due_reports} not due yet
                    {row.next_due && ` · from ${formatDate(row.next_due)}`}
                </span>
            )}
        </div>
    );
}

function NewSettlementDialog({
    merchants,
    clients,
    payoutCurrencies,
    products,
    preset,
    onClose,
}: {
    merchants: Props['merchants'];
    clients: Props['clients'];
    payoutCurrencies: Props['payoutCurrencies'];
    products: Props['products'];
    preset: Target | null;
    onClose: () => void;
}) {
    const [kind, setKind] = useState<'client' | 'merchant'>(
        preset && 'merchant' in preset
            ? 'merchant'
            : clients.length > 0
              ? 'client'
              : 'merchant',
    );
    const form = useForm({
        client: String(
            preset && 'client' in preset
                ? preset.client
                : (clients[0]?.id ?? ''),
        ),
        merchant:
            preset && 'merchant' in preset
                ? preset.merchant
                : (merchants[0]?.public_id ?? ''),
        payout_currency: payoutCurrencies[0]?.value ?? 'USDC',
        product: preset?.product ?? 'card',
        include_early: false,
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            payout_currency: data.payout_currency,
                            product: data.product,
                            due_only: !data.include_early,
                            ...(kind === 'client'
                                ? { client: data.client }
                                : { merchant: data.merchant }),
                        }));
                        form.post(admin.settlements.store.url());
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>New settlement</DialogTitle>
                        <DialogDescription>
                            The draft picks up every completed report and
                            released reserve that is not paid yet: of all the
                            client's companies, or of one merchant. You can
                            remove lines and add adjustments before approving.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="inline-flex w-fit rounded-lg border p-0.5">
                        {(['client', 'merchant'] as const).map((k) => (
                            <button
                                key={k}
                                type="button"
                                onClick={() => setKind(k)}
                                className={cn(
                                    'rounded-md px-3 py-1 text-sm font-medium',
                                    kind === k
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {k === 'client' ? 'Client' : 'Merchant'}
                            </button>
                        ))}
                    </div>
                    {kind === 'client' ? (
                        <Field label="Client" error={form.errors.client}>
                            <Select
                                value={form.data.client}
                                onValueChange={(v) => form.setData('client', v)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {clients.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    ) : (
                        <Field label="Merchant" error={form.errors.merchant}>
                            <Select
                                value={form.data.merchant}
                                onValueChange={(v) =>
                                    form.setData('merchant', v)
                                }
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
                    )}
                    {products.length > 0 && (
                        <Field label="Product" error={form.errors.product}>
                            <Select
                                value={form.data.product}
                                onValueChange={(v) =>
                                    form.setData('product', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {products.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={p.value}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    )}
                    {preset?.scheduled && (
                        <label className="flex items-start gap-2 text-sm">
                            <Checkbox
                                checked={form.data.include_early}
                                onCheckedChange={(on) =>
                                    form.setData('include_early', on === true)
                                }
                            />
                            <span>
                                Also include reports that are not due yet
                                <span className="block text-xs text-muted-foreground">
                                    By default only reports due today or overdue
                                    go into the draft.
                                </span>
                            </span>
                        </label>
                    )}
                    <Field
                        label="Pay out in"
                        error={form.errors.payout_currency}
                    >
                        <Select
                            value={form.data.payout_currency}
                            onValueChange={(v) =>
                                form.setData('payout_currency', v)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {payoutCurrencies.map((c) => (
                                    <SelectItem key={c.value} value={c.value}>
                                        {c.label}
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
                            disabled={
                                form.processing ||
                                !(kind === 'client'
                                    ? form.data.client
                                    : form.data.merchant)
                            }
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
    pendingClients,
    clients,
    payoutCurrencies,
    products,
    filters,
    statuses,
    merchants,
    cancelledCount,
}: Props) {
    const [creating, setCreating] = useState<Target | null | false>(false);
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
                    description="Payouts to clients and merchants in USDC, USDT and other currencies. One settlement can cover several days, companies and currencies; draft → approved → settled."
                    actions={
                        <Button onClick={() => setCreating(null)}>
                            <Plus />
                            New settlement
                        </Button>
                    }
                />

                {pending.length + pendingClients.length > 0 && (
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
                                {pendingClients.map((row) => (
                                    <TableRow
                                        key={`c${row.id}-${row.product}-${row.currency}`}
                                    >
                                        <TableCell className="font-medium">
                                            <span className="inline-flex items-center gap-2">
                                                <Building2 className="size-4 text-muted-foreground" />
                                                {row.name}
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    client · {row.companies}{' '}
                                                    {row.companies === 1
                                                        ? 'company'
                                                        : 'companies'}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {products.length > 0 && (
                                                <span className="mr-2 text-xs text-muted-foreground">
                                                    {row.product === 'c2b'
                                                        ? 'C2B'
                                                        : 'Card'}
                                                </span>
                                            )}
                                            <CurrencyBadge
                                                currency={row.currency}
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            <DueInfo row={row} />
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(
                                                row.scheduled
                                                    ? row.due_payout
                                                    : row.payout,
                                                row.currency,
                                            )}
                                            {row.scheduled &&
                                                row.payout !==
                                                    row.due_payout && (
                                                    <div className="text-xs font-normal text-muted-foreground">
                                                        of{' '}
                                                        {formatMoney(
                                                            row.payout,
                                                            row.currency,
                                                        )}
                                                    </div>
                                                )}
                                        </TableCell>
                                        <TableCell className="w-0 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setCreating({
                                                        client: row.id,
                                                        product: row.product,
                                                        scheduled:
                                                            row.scheduled,
                                                    })
                                                }
                                            >
                                                {row.scheduled
                                                    ? 'Settle due'
                                                    : 'Settle client'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {pending.map((row) => (
                                    <TableRow
                                        key={`${row.public_id}-${row.product}-${row.currency}`}
                                    >
                                        <TableCell className="font-medium">
                                            {row.name}
                                        </TableCell>
                                        <TableCell>
                                            {products.length > 0 && (
                                                <span className="mr-2 text-xs text-muted-foreground">
                                                    {row.product === 'c2b'
                                                        ? 'C2B'
                                                        : 'Card'}
                                                </span>
                                            )}
                                            <CurrencyBadge
                                                currency={row.currency}
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            <DueInfo row={row} />
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(
                                                row.scheduled
                                                    ? row.due_payout
                                                    : row.payout,
                                                row.currency,
                                            )}
                                            {row.scheduled &&
                                                row.payout !==
                                                    row.due_payout && (
                                                    <div className="text-xs font-normal text-muted-foreground">
                                                        of{' '}
                                                        {formatMoney(
                                                            row.payout,
                                                            row.currency,
                                                        )}
                                                    </div>
                                                )}
                                        </TableCell>
                                        <TableCell className="w-0 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setCreating({
                                                        merchant: row.public_id,
                                                        product: row.product,
                                                        scheduled:
                                                            row.scheduled,
                                                    })
                                                }
                                            >
                                                {row.scheduled
                                                    ? 'Settle due'
                                                    : 'Settle'}
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
                        {cancelledCount > 0 &&
                            filters.status !== 'cancelled' && (
                                <Button
                                    variant={
                                        filters.cancelled
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        apply({
                                            cancelled: filters.cancelled
                                                ? ''
                                                : '1',
                                        })
                                    }
                                >
                                    {filters.cancelled ? 'Hide' : 'Show'}{' '}
                                    cancelled ({cancelledCount})
                                </Button>
                            )}
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
                                        <TableCell>
                                            {s.client ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <Building2 className="size-3.5 text-muted-foreground" />
                                                    {s.client.name}
                                                </span>
                                            ) : (
                                                s.merchant.name
                                            )}
                                        </TableCell>
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
                    clients={clients}
                    payoutCurrencies={payoutCurrencies}
                    products={products}
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
