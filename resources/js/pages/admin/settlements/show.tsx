import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Download,
    FileCheck2,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { StatusBadge } from '@/components/admin/status-badge';
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
import { Input } from '@/components/ui/input';
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
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import { settlementColors } from '@/components/admin/settlements';
import type { SettlementItem } from '@/components/admin/settlements';

type Line = {
    id: number;
    type: string;
    type_label: string;
    description: string;
    report_id: number | null;
    currency: string;
    amount: string;
    rate: string;
    amount_payout: string;
};

type Props = {
    settlement: SettlementItem & {
        rates: Record<string, string>;
        notes: string | null;
        wallet_id: number | null;
        creator: string | null;
        approver: string | null;
        approved_at: string | null;
        settler: string | null;
        settled_at: string | null;
        has_proof: boolean;
        cancelled_at: string | null;
        cancel_reason: string | null;
        missing_rates: string[];
    };
    lines: Line[];
    wallets: {
        id: number;
        label: string;
        currency: string;
        network: string;
        address: string;
    }[];
    availableReports: {
        id: number;
        report_date: string;
        mid: string;
        currency: string;
        net_payout: string;
    }[];
    availableReleases: {
        id: number;
        mid: string | null;
        currency: string;
        amount: string;
        note: string | null;
    }[];
};

const NONE = 'none';

function AddLinesDialog({
    settlement,
    reports,
    releases,
    onClose,
}: {
    settlement: Props['settlement'];
    reports: Props['availableReports'];
    releases: Props['availableReleases'];
    onClose: () => void;
}) {
    const [reportIds, setReportIds] = useState<number[]>([]);
    const [releaseIds, setReleaseIds] = useState<number[]>([]);
    const toggle = (list: number[], set: (v: number[]) => void, id: number) =>
        set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add reports & releases</DialogTitle>
                    <DialogDescription>
                        Only what no other active settlement pays yet.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    {reports.map((r) => (
                        <label
                            key={`r${r.id}`}
                            className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                        >
                            <Checkbox
                                checked={reportIds.includes(r.id)}
                                onCheckedChange={() =>
                                    toggle(reportIds, setReportIds, r.id)
                                }
                            />
                            <span className="flex-1">
                                Report {formatDate(r.report_date)} ·{' '}
                                <span className="font-mono text-xs">
                                    {r.mid}
                                </span>
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(r.net_payout, r.currency)}
                            </span>
                        </label>
                    ))}
                    {releases.map((r) => (
                        <label
                            key={`e${r.id}`}
                            className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                        >
                            <Checkbox
                                checked={releaseIds.includes(r.id)}
                                onCheckedChange={() =>
                                    toggle(releaseIds, setReleaseIds, r.id)
                                }
                            />
                            <span className="flex-1">
                                Reserve release ·{' '}
                                <span className="font-mono text-xs">
                                    {r.mid}
                                </span>{' '}
                                {r.note && (
                                    <span className="text-muted-foreground">
                                        · {r.note}
                                    </span>
                                )}
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(r.amount, r.currency)}
                            </span>
                        </label>
                    ))}
                    {reports.length + releases.length === 0 && (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            Nothing left to add.
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reportIds.length + releaseIds.length === 0}
                        onClick={() =>
                            router.post(
                                admin.settlements.lines.store.url(
                                    settlement.id,
                                ),
                                {
                                    report_ids: reportIds,
                                    release_ids: releaseIds,
                                },
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                    >
                        Add {reportIds.length + releaseIds.length}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AdjustmentDialog({
    settlement,
    currencies,
    onClose,
}: {
    settlement: Props['settlement'];
    currencies: string[];
    onClose: () => void;
}) {
    const form = useForm({
        description: '',
        currency: currencies[0] ?? 'EUR',
        amount: '',
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(
                            admin.settlements.adjustments.store.url(
                                settlement.id,
                            ),
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Manual adjustment</DialogTitle>
                        <DialogDescription>
                            Positive = we owe the merchant more; negative =
                            deduct (e.g. "Previous overpayment").
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Description"
                        htmlFor="adj-desc"
                        error={form.errors.description}
                    >
                        <Input
                            id="adj-desc"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            placeholder="Previous overpayment"
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Currency" error={form.errors.currency}>
                            <Select
                                value={form.data.currency}
                                onValueChange={(v) =>
                                    form.setData('currency', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {['EUR', 'USD', 'GBP'].map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Amount"
                            htmlFor="adj-amount"
                            error={form.errors.amount}
                        >
                            <Input
                                id="adj-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData('amount', e.target.value)
                                }
                                placeholder="-120.00"
                            />
                        </Field>
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Add
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SettleDialog({
    settlement,
    onClose,
}: {
    settlement: Props['settlement'];
    onClose: () => void;
}) {
    const form = useForm<{ tx_hash: string; proof: File | null }>({
        tx_hash: '',
        proof: null,
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(admin.settlements.settle.url(settlement.id), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Mark {settlement.number} as paid
                        </DialogTitle>
                        <DialogDescription>
                            {formatMoney(settlement.total_payout)}{' '}
                            {settlement.payout_currency} sent. Record the
                            transaction and attach the confirmation.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Transaction hash"
                        htmlFor="tx"
                        error={form.errors.tx_hash}
                    >
                        <Input
                            id="tx"
                            value={form.data.tx_hash}
                            onChange={(e) =>
                                form.setData('tx_hash', e.target.value)
                            }
                            className="font-mono"
                        />
                    </Field>
                    <Field
                        label="Confirmation (PDF or image)"
                        htmlFor="proof"
                        error={form.errors.proof}
                    >
                        <Input
                            id="proof"
                            type="file"
                            accept=".pdf,.png,.jpg,.jpeg,.webp"
                            onChange={(e) =>
                                form.setData(
                                    'proof',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.tx_hash}
                        >
                            {form.processing && <Spinner />}
                            Mark as paid
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CancelDialog({
    settlement,
    onClose,
}: {
    settlement: Props['settlement'];
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(admin.settlements.cancel.url(settlement.id), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Cancel {settlement.number}?</DialogTitle>
                        <DialogDescription>
                            Its reports and reserve releases become available
                            again.{' '}
                            {settlement.status === 'settled' &&
                                'It was already marked as paid — make sure the money is accounted for.'}
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Reason"
                        htmlFor="reason"
                        error={form.errors.reason}
                    >
                        <Input
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Keep it
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing || !form.data.reason}
                        >
                            Cancel settlement
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SettlementShow({
    settlement,
    lines,
    wallets,
    availableReports,
    availableReleases,
}: Props) {
    const [dialog, setDialog] = useState<
        'add' | 'adjust' | 'settle' | 'cancel' | null
    >(null);
    const draft = settlement.status === 'draft';
    const currencies = Array.from(new Set(lines.map((l) => l.currency)));
    const form = useForm({
        rates: Object.fromEntries(
            currencies.map((c) => [c, settlement.rates[c] ?? '']),
        ) as Record<string, string>,
        wallet_id: settlement.wallet_id ? String(settlement.wallet_id) : '',
        notes: settlement.notes ?? '',
    });
    const wallet = wallets.find((w) => w.id === settlement.wallet_id);

    return (
        <>
            <Head title={settlement.number} />
            <PageBody>
                <Link
                    href={admin.settlements.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Settlements
                </Link>
                <PageHeader
                    title={settlement.number}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <StatusBadge
                                name={settlement.status_label}
                                color={
                                    settlementColors[settlement.status] ??
                                    'slate'
                                }
                            />
                            <Link
                                href={admin.merchants.show(
                                    settlement.merchant.public_id,
                                )}
                                className="hover:underline"
                            >
                                {settlement.merchant.name}
                            </Link>
                            {settlement.creator && (
                                <span>· created by {settlement.creator}</span>
                            )}
                        </span>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={admin.settlements.pdf.url(
                                        settlement.id,
                                    )}
                                >
                                    <Download />
                                    Statement PDF
                                </a>
                            </Button>
                            {draft && (
                                <Button
                                    onClick={() =>
                                        router.post(
                                            admin.settlements.approve.url(
                                                settlement.id,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Check />
                                    Approve
                                </Button>
                            )}
                            {settlement.status === 'approved' && (
                                <Button onClick={() => setDialog('settle')}>
                                    <FileCheck2 />
                                    Mark as paid
                                </Button>
                            )}
                            {settlement.status !== 'cancelled' && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('cancel')}
                                >
                                    <XCircle />
                                    Cancel
                                </Button>
                            )}
                        </>
                    }
                />
                <PageErrors keys={['settlement', 'rates', 'wallet_id']} />

                {settlement.cancelled_at && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                        Cancelled {formatDateTime(settlement.cancelled_at)}:{' '}
                        {settlement.cancel_reason}
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center justify-between gap-2 border-b px-5 py-3">
                            <h2 className="text-sm font-semibold">Lines</h2>
                            {draft && (
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setDialog('add')}
                                    >
                                        <Plus />
                                        Reports
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setDialog('adjust')}
                                    >
                                        <Plus />
                                        Adjustment
                                    </Button>
                                </div>
                            )}
                        </header>
                        {lines.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                No lines yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Line</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Rate
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {settlement.payout_currency}
                                        </TableHead>
                                        {draft && <TableHead className="w-0" />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell>
                                                <div className="text-xs text-muted-foreground">
                                                    {line.type_label}
                                                </div>
                                                {line.report_id &&
                                                line.type === 'report' ? (
                                                    <Link
                                                        href={admin.reports.show(
                                                            line.report_id,
                                                        )}
                                                        className="hover:underline"
                                                    >
                                                        {line.description}
                                                    </Link>
                                                ) : (
                                                    line.description
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    line.amount,
                                                    line.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {Number(line.rate) || '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    line.amount_payout,
                                                )}
                                            </TableCell>
                                            {draft && (
                                                <TableCell>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        aria-label="Remove line"
                                                        onClick={() =>
                                                            router.delete(
                                                                admin.settlements.lines.destroy.url(
                                                                    {
                                                                        settlement:
                                                                            settlement.id,
                                                                        line: line.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                    <TableRow className="border-t-2 hover:bg-transparent">
                                        <TableCell
                                            colSpan={3}
                                            className="font-semibold"
                                        >
                                            Total payout
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">
                                            {formatMoney(
                                                settlement.total_payout,
                                            )}{' '}
                                            {settlement.payout_currency}
                                        </TableCell>
                                        {draft && <TableCell />}
                                    </TableRow>
                                </TableBody>
                            </Table>
                        )}
                    </section>

                    <aside className="grid content-start gap-4">
                        {draft ? (
                            <form
                                className="grid gap-4 rounded-xl border bg-card p-5 shadow-xs"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.put(
                                        admin.settlements.update.url(
                                            settlement.id,
                                        ),
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <h2 className="text-sm font-semibold">
                                    Rates to {settlement.payout_currency}
                                </h2>
                                {currencies.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Add lines first.
                                    </p>
                                )}
                                {currencies.map((c) => (
                                    <Field
                                        key={c}
                                        label={`1 ${c} =`}
                                        htmlFor={`rate-${c}`}
                                        error={
                                            form.errors[
                                                `rates.${c}` as keyof typeof form.errors
                                            ]
                                        }
                                    >
                                        <Input
                                            id={`rate-${c}`}
                                            inputMode="decimal"
                                            value={form.data.rates[c] ?? ''}
                                            onChange={(e) =>
                                                form.setData('rates', {
                                                    ...form.data.rates,
                                                    [c]: e.target.value,
                                                })
                                            }
                                            className={
                                                settlement.missing_rates.includes(
                                                    c,
                                                )
                                                    ? 'border-warning'
                                                    : undefined
                                            }
                                        />
                                    </Field>
                                ))}
                                <p className="text-xs text-muted-foreground">
                                    Defaults to the latest FX rate to{' '}
                                    {settlement.payout_currency}; adjust if
                                    needed.
                                </p>
                                <Field
                                    label="Pay to wallet"
                                    error={form.errors.wallet_id}
                                >
                                    <Select
                                        value={form.data.wallet_id || NONE}
                                        onValueChange={(v) =>
                                            form.setData(
                                                'wallet_id',
                                                v === NONE ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE}>
                                                Not chosen
                                            </SelectItem>
                                            {wallets.map((w) => (
                                                <SelectItem
                                                    key={w.id}
                                                    value={String(w.id)}
                                                >
                                                    {w.label} · {w.currency}{' '}
                                                    {w.network}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field
                                    label="Notes"
                                    htmlFor="notes"
                                    error={form.errors.notes}
                                >
                                    <Textarea
                                        id="notes"
                                        rows={2}
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={form.processing}
                                >
                                    Save
                                </Button>
                            </form>
                        ) : (
                            <section className="grid gap-2 rounded-xl border bg-card p-5 text-sm shadow-xs">
                                <h2 className="text-sm font-semibold">
                                    Payout
                                </h2>
                                {Object.entries(settlement.rates).map(
                                    ([c, r]) => (
                                        <div
                                            key={c}
                                            className="flex justify-between"
                                        >
                                            <span className="text-muted-foreground">
                                                1 {c}
                                            </span>
                                            <span className="tabular-nums">
                                                {Number(r)}{' '}
                                                {settlement.payout_currency}
                                            </span>
                                        </div>
                                    ),
                                )}
                                {wallet && (
                                    <p className="break-all">
                                        <span className="text-muted-foreground">
                                            To:
                                        </span>{' '}
                                        {wallet.currency} {wallet.network} ·{' '}
                                        <span className="font-mono text-xs">
                                            {wallet.address}
                                        </span>
                                    </p>
                                )}
                                {settlement.approved_at && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Approved:
                                        </span>{' '}
                                        {formatDateTime(settlement.approved_at)}{' '}
                                        · {settlement.approver}
                                    </p>
                                )}
                                {settlement.settled_at && (
                                    <p>
                                        <span className="text-muted-foreground">
                                            Paid:
                                        </span>{' '}
                                        {formatDateTime(settlement.settled_at)}{' '}
                                        · {settlement.settler}
                                    </p>
                                )}
                                {settlement.tx_hash && (
                                    <p className="font-mono text-xs break-all">
                                        {settlement.tx_hash}
                                    </p>
                                )}
                                {settlement.has_proof && (
                                    <a
                                        href={admin.settlements.proof.url(
                                            settlement.id,
                                        )}
                                        className="text-primary hover:underline"
                                    >
                                        Download confirmation
                                    </a>
                                )}
                                {settlement.notes && (
                                    <p className="text-muted-foreground">
                                        {settlement.notes}
                                    </p>
                                )}
                            </section>
                        )}
                    </aside>
                </div>
            </PageBody>
            {dialog === 'add' && (
                <AddLinesDialog
                    settlement={settlement}
                    reports={availableReports}
                    releases={availableReleases}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'adjust' && (
                <AdjustmentDialog
                    settlement={settlement}
                    currencies={currencies}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'settle' && (
                <SettleDialog
                    settlement={settlement}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'cancel' && (
                <CancelDialog
                    settlement={settlement}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

SettlementShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Settlements', href: admin.settlements.index() },
        {
            title: props.settlement.number,
            href: admin.settlements.show(props.settlement.id),
        },
    ],
});
