import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Copy,
    Download,
    FileCheck2,
    Plus,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import { settlementColors } from '@/components/admin/settlements';
import type { SettlementItem } from '@/components/admin/settlements';
import type { Option } from '@/types';

type Due = { date: string; state: 'not_due' | 'due' | 'overdue' };

/** Whether a report is due for payout by its merchant's T+N schedule. */
function DueBadge({ due }: { due: Due | null }) {
    if (due === null) {
        return null;
    }

    const style = {
        due: ['Due today', 'text-emerald-600'],
        overdue: [`Overdue since ${formatDate(due.date)}`, 'text-amber-600'],
        not_due: [`Not due until ${formatDate(due.date)}`, 'text-rose-600'],
    }[due.state];

    return (
        <span className={cn('text-xs font-medium', style[1])}>{style[0]}</span>
    );
}

/** One line above the table: how many reports are due, early or have no payout schedule. */
function DueSummary({ lines }: { lines: Line[] }) {
    const reports = lines.filter((l) => l.type === 'report');
    if (reports.length === 0) {
        return null;
    }

    const count = (state: Due['state']) =>
        reports.filter((l) => l.due?.state === state).length;
    const early = reports.filter((l) => l.due?.state === 'not_due');
    const unscheduled = reports.filter((l) => l.due === null).length;
    const ready = count('due') + count('overdue');
    const latest = early
        .map((l) => l.due!.date)
        .sort()
        .at(-1);

    return (
        <div
            className={cn(
                'rounded-lg border px-4 py-3 text-sm',
                early.length > 0
                    ? 'border-rose-500/40 bg-rose-500/5'
                    : 'bg-card',
            )}
        >
            <div className="font-medium">
                {early.length > 0
                    ? `Too early: ${early.length} of ${reports.length} reports are not due yet`
                    : ready === reports.length
                      ? 'All reports are due for payout'
                      : 'Payout schedule'}
            </div>
            <div className="mt-0.5 text-xs text-muted-foreground">
                {[
                    ready > 0 && `${ready} due or overdue`,
                    early.length > 0 &&
                        `${early.length} not due until ${formatDate(latest)}`,
                    unscheduled > 0 &&
                        `${unscheduled} without a payout schedule (set T+N on the merchant)`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </div>
        </div>
    );
}

type Overview = {
    client: {
        id: number;
        name: string;
        invoice_name: string;
        invoice_address: string | null;
        invoice_email: string | null;
        notes: string | null;
        companies: { id: number; name: string; merchants: number }[];
        portal_users: {
            name: string;
            email: string;
            is_active: boolean;
            last_login_at: string | null;
        }[];
        settlements: {
            count: number;
            settled: number;
            last_settled_at: string | null;
        };
    } | null;
    wallet: {
        label: string;
        type: string;
        currency: string;
        network: string | null;
        address: string;
        owner: string | null;
        notes: string | null;
    } | null;
    companies: {
        name: string;
        merchants: {
            public_id: string;
            name: string;
            website: string | null;
            lag_days: number | null;
            mids: {
                mid: string;
                label: string | null;
                currency: string;
                bank: string | null;
                bank_status: string | null;
                gate: string | null;
                reports: number;
                amount: string;
                from: string | null;
                to: string | null;
            }[];
        }[];
    }[];
    other_lines: {
        type: string;
        description: string;
        currency: string;
        amount: string;
    }[];
};

/** Who is paid and for what: the client and its requisites, the wallet, then companies → merchants → terminals. */
function SettlementParties({
    overview,
    payoutCurrency,
    product,
}: {
    overview: Overview;
    payoutCurrency: string;
    product: string;
}) {
    const { client, wallet, companies, other_lines } = overview;
    const merchants = companies.flatMap((c) => c.merchants);
    const mids = merchants.flatMap((m) => m.mids);
    const reports = mids.reduce((n, m) => n + m.reports, 0);

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-4 shadow-xs">
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="grid content-start gap-1 text-sm">
                    <div className="text-xs font-medium text-muted-foreground uppercase">
                        Client
                    </div>
                    {client ? (
                        <>
                            <Link
                                href={admin.clients.show(client.id)}
                                className="font-medium hover:underline"
                            >
                                {client.name}
                            </Link>
                            <div>Invoice name: {client.invoice_name}</div>
                            <div className="text-xs whitespace-pre-line text-muted-foreground">
                                {client.invoice_address ||
                                    'No invoice address yet'}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {client.invoice_email ||
                                    'No invoice e-mail yet'}
                            </div>
                            {client.notes && (
                                <div className="mt-1 text-xs whitespace-pre-line text-amber-600">
                                    {client.notes}
                                </div>
                            )}
                            <div className="mt-2 grid gap-1 border-t pt-2 text-xs text-muted-foreground">
                                <div>
                                    Companies:{' '}
                                    {client.companies.length === 0
                                        ? 'none'
                                        : client.companies
                                              .map(
                                                  (c) =>
                                                      `${c.name} (${c.merchants})`,
                                              )
                                              .join(', ')}
                                </div>
                                <div>
                                    Portal access:{' '}
                                    {client.portal_users.length === 0
                                        ? 'no portal users'
                                        : client.portal_users
                                              .map(
                                                  (u) =>
                                                      `${u.name} <${u.email}>${u.is_active ? '' : ' (off)'}${u.last_login_at ? ` · last in ${formatDate(u.last_login_at)}` : ''}`,
                                              )
                                              .join('; ')}
                                </div>
                                <div>
                                    Settlements: {client.settlements.count}
                                    {client.settlements.settled > 0 &&
                                        ` · ${client.settlements.settled} paid, last ${formatDate(client.settlements.last_settled_at)}`}
                                </div>
                            </div>
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            The merchant is not in a client.
                        </span>
                    )}
                </div>
                <div className="grid content-start gap-1 text-sm">
                    <div className="text-xs font-medium text-muted-foreground uppercase">
                        Pays to
                    </div>
                    {wallet ? (
                        <>
                            <div className="font-medium">
                                {wallet.label}
                                {wallet.owner && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {wallet.owner}
                                    </span>
                                )}
                            </div>
                            <div>
                                {wallet.currency}
                                {wallet.network && ` · ${wallet.network}`}
                                {wallet.currency.toUpperCase() !==
                                    payoutCurrency && (
                                    <span className="text-xs text-amber-600">
                                        {' '}
                                        (settlement is paid in {payoutCurrency})
                                    </span>
                                )}
                            </div>
                            <div className="flex items-start gap-1">
                                <span className="font-mono text-xs break-all">
                                    {wallet.address}
                                </span>
                                <CopyValue value={wallet.address} />
                            </div>
                            {wallet.notes && (
                                <div className="text-xs text-muted-foreground">
                                    {wallet.notes}
                                </div>
                            )}
                        </>
                    ) : (
                        <span className="text-destructive">
                            No wallet chosen yet.
                        </span>
                    )}
                </div>
                <div className="grid content-start gap-1 text-sm">
                    <div className="text-xs font-medium text-muted-foreground uppercase">
                        Scope
                    </div>
                    <div>
                        {companies.length} compan
                        {companies.length === 1 ? 'y' : 'ies'} ·{' '}
                        {merchants.length} merchant
                        {merchants.length === 1 ? '' : 's'} · {mids.length} MID
                        {mids.length === 1 ? '' : 's'}
                    </div>
                    <div className="text-muted-foreground">
                        {reports} daily report{reports === 1 ? '' : 's'} ·{' '}
                        {product} · paid in {payoutCurrency}
                    </div>
                </div>
            </div>

            {companies.map((company) => (
                <div key={company.name} className="grid gap-2 border-t pt-3">
                    <div className="text-sm font-semibold">{company.name}</div>
                    {company.merchants.map((merchant) => (
                        <div
                            key={merchant.public_id}
                            className="overflow-x-auto rounded-lg border"
                        >
                            <div className="flex flex-wrap items-baseline gap-x-2 border-b bg-muted/30 px-3 py-2 text-sm">
                                <Link
                                    href={admin.merchants.show(
                                        merchant.public_id,
                                    )}
                                    className="font-medium hover:underline"
                                >
                                    {merchant.name}
                                </Link>
                                {merchant.website && (
                                    <span className="text-xs text-muted-foreground">
                                        {merchant.website}
                                    </span>
                                )}
                                <span className="text-xs text-muted-foreground">
                                    {merchant.lag_days === null
                                        ? 'no payout schedule'
                                        : `T+${merchant.lag_days}`}
                                </span>
                            </div>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="px-3 py-1.5 font-medium">
                                            MID
                                        </th>
                                        <th className="px-3 py-1.5 font-medium">
                                            Acquirer
                                        </th>
                                        <th className="px-3 py-1.5 font-medium">
                                            Gateway
                                        </th>
                                        <th className="px-3 py-1.5 font-medium">
                                            Reports
                                        </th>
                                        <th className="px-3 py-1.5 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {merchant.mids.map((mid) => (
                                        <tr key={mid.mid} className="border-t">
                                            <td className="px-3 py-1.5">
                                                <span className="font-mono text-xs">
                                                    {mid.mid}
                                                </span>
                                                {mid.label && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {' '}
                                                        · {mid.label}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5">
                                                {mid.bank ?? '—'}
                                                {mid.bank_status && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {' '}
                                                        · {mid.bank_status}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5">
                                                {mid.gate ?? '—'}
                                            </td>
                                            <td className="px-3 py-1.5 text-xs text-muted-foreground">
                                                {mid.reports} ·{' '}
                                                {mid.from === mid.to
                                                    ? formatDate(mid.from)
                                                    : `${formatDate(mid.from)} – ${formatDate(mid.to)}`}
                                            </td>
                                            <td className="px-3 py-1.5 text-right tabular-nums">
                                                {formatMoney(
                                                    mid.amount,
                                                    mid.currency,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            ))}

            {other_lines.length > 0 && (
                <div className="grid gap-1 border-t pt-3 text-sm">
                    <div className="text-xs font-medium text-muted-foreground uppercase">
                        Not tied to a terminal
                    </div>
                    {other_lines.map((line, i) => (
                        <div key={i} className="flex justify-between gap-3">
                            <span>
                                {line.type}
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {line.description}
                                </span>
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(line.amount, line.currency)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

type Line = {
    id: number;
    type: string;
    type_label: string;
    description: string;
    report_id: number | null;
    due: Due | null;
    owner: string | null;
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
        locked_scope: boolean;
        early: { id: number; report_date: string; due: string | null }[];
    };
    overview: Overview;
    payoutCurrencies: Option[];
    lines: Line[];
    wallets: {
        id: number;
        label: string;
        currency: string;
        network: string;
        address: string;
        merchant: string;
    }[];
    availableReports: {
        id: number;
        report_date: string;
        due: Due | null;
        mid: string;
        owner: string;
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
    const owners = Array.from(new Set(reports.map((r) => r.owner)));

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
                    {owners.map((owner) => (
                        <div key={owner} className="grid gap-2">
                            {settlement.client && (
                                <label className="flex items-center gap-3 pt-2 text-sm font-medium">
                                    <Checkbox
                                        checked={reports
                                            .filter((r) => r.owner === owner)
                                            .every((r) =>
                                                reportIds.includes(r.id),
                                            )}
                                        onCheckedChange={(checked) =>
                                            setReportIds((ids) => [
                                                ...ids.filter(
                                                    (id) =>
                                                        !reports.some(
                                                            (r) =>
                                                                r.id === id &&
                                                                r.owner ===
                                                                    owner,
                                                        ),
                                                ),
                                                ...(checked === true
                                                    ? reports
                                                          .filter(
                                                              (r) =>
                                                                  r.owner ===
                                                                  owner,
                                                          )
                                                          .map((r) => r.id)
                                                    : []),
                                            ])
                                        }
                                    />
                                    {owner}
                                </label>
                            )}
                            {reports
                                .filter((r) => r.owner === owner)
                                .map((r) => (
                                    <label
                                        key={`r${r.id}`}
                                        className="flex items-center gap-3 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={reportIds.includes(r.id)}
                                            onCheckedChange={() =>
                                                toggle(
                                                    reportIds,
                                                    setReportIds,
                                                    r.id,
                                                )
                                            }
                                        />
                                        <span className="flex-1">
                                            Report {formatDate(r.report_date)} ·{' '}
                                            <span className="font-mono text-xs">
                                                {r.mid}
                                            </span>
                                            <span className="ml-2">
                                                <DueBadge due={r.due} />
                                            </span>
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                r.net_payout,
                                                r.currency,
                                            )}
                                        </span>
                                    </label>
                                ))}
                        </div>
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

type PayoutWallet = Props['wallets'][number];

function CopyValue({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7 shrink-0"
            aria-label="Copy"
            onClick={() => {
                void navigator.clipboard?.writeText(value);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            }}
        >
            {copied ? <Check className="text-emerald-600" /> : <Copy />}
        </Button>
    );
}

/**
 * Where the money has to go: amount, currency, network and address of the chosen wallet.
 */
function PayoutDestination({
    settlement,
    wallet,
}: {
    settlement: Props['settlement'];
    wallet: PayoutWallet | undefined;
}) {
    const amount = formatMoney(settlement.total_payout);
    const mismatch =
        wallet !== undefined &&
        wallet.currency.toUpperCase() !== settlement.payout_currency;

    if (!wallet) {
        return (
            <p className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm">
                No wallet chosen: there is nowhere to send the money.
            </p>
        );
    }

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm">
            <div>
                <div className="text-xs text-muted-foreground">Send</div>
                <div className="flex items-center gap-1 text-xl font-semibold tabular-nums">
                    {amount} {settlement.payout_currency}
                    <CopyValue value={settlement.total_payout} />
                </div>
            </div>
            <dl className="grid grid-cols-[6rem_1fr] gap-y-2">
                <dt className="text-muted-foreground">Currency</dt>
                <dd className="font-medium">{wallet.currency}</dd>
                <dt className="text-muted-foreground">Network</dt>
                <dd className="font-medium">{wallet.network || '—'}</dd>
                <dt className="text-muted-foreground">Wallet</dt>
                <dd>
                    {wallet.label} · {wallet.merchant}
                </dd>
                <dt className="text-muted-foreground">Address</dt>
                <dd className="flex items-start gap-1">
                    <span className="font-mono text-xs break-all">
                        {wallet.address}
                    </span>
                    <CopyValue value={wallet.address} />
                </dd>
            </dl>
            {mismatch && (
                <p className="text-xs text-amber-600">
                    The wallet is for {wallet.currency}, but the settlement is
                    paid in {settlement.payout_currency}. Check before sending.
                </p>
            )}
        </div>
    );
}

function ApproveDialog({
    settlement,
    wallet,
    onClose,
}: {
    settlement: Props['settlement'];
    wallet: PayoutWallet | undefined;
    onClose: () => void;
}) {
    const [confirmEarly, setConfirmEarly] = useState(false);
    const early = settlement.early;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Approve {settlement.number}?</DialogTitle>
                    <DialogDescription>
                        After approval the lines are locked. This is where the
                        money has to be sent.
                    </DialogDescription>
                </DialogHeader>
                {early.length > 0 && (
                    <div className="grid gap-2 rounded-lg border border-rose-500/40 bg-rose-500/5 p-3 text-sm">
                        <p className="font-medium text-rose-600">
                            {early.length} report(s) are not due for payout yet
                        </p>
                        <ul className="text-xs text-muted-foreground">
                            {early.map((r) => (
                                <li key={r.id}>
                                    Report {formatDate(r.report_date)}
                                    {r.due && ` · due ${formatDate(r.due)}`}
                                </li>
                            ))}
                        </ul>
                        <label className="flex items-start gap-2">
                            <Checkbox
                                checked={confirmEarly}
                                onCheckedChange={(on) =>
                                    setConfirmEarly(on === true)
                                }
                            />
                            <span>
                                I understand: pay these reports before their
                                date
                            </span>
                        </label>
                    </div>
                )}
                <PayoutDestination settlement={settlement} wallet={wallet} />
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Back
                        </Button>
                    </DialogClose>
                    <Button
                        disabled={early.length > 0 && !confirmEarly}
                        onClick={() =>
                            router.post(
                                admin.settlements.approve.url(settlement.id),
                                { confirm_early: confirmEarly },
                                { preserveScroll: true, onFinish: onClose },
                            )
                        }
                    >
                        <Check />
                        Approve
                    </Button>
                </DialogFooter>
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
    overview,
    lines,
    wallets,
    payoutCurrencies,
    availableReports,
    availableReleases,
}: Props) {
    const [dialog, setDialog] = useState<
        'add' | 'adjust' | 'approve' | 'settle' | 'cancel' | null
    >(null);
    const draft = settlement.status === 'draft';
    const currencies = Array.from(new Set(lines.map((l) => l.currency)));
    // What the lines add up to in each currency, before and after conversion (in cents, so no rounding drift).
    const currencyTotals = currencies.map((currency) => {
        const own = lines.filter((l) => l.currency === currency);
        const cents = (value: string) => Math.round(Number(value) * 100);

        return {
            currency,
            lines: own.length,
            amount: own.reduce((sum, l) => sum + cents(l.amount), 0) / 100,
            payout:
                own.reduce((sum, l) => sum + cents(l.amount_payout), 0) / 100,
            rate: Number(own[0]?.rate) || null,
        };
    });
    const form = useForm({
        rates: Object.fromEntries(
            currencies.map((c) => [c, settlement.rates[c] ?? '']),
        ) as Record<string, string>,
        wallet_id: settlement.wallet_id ? String(settlement.wallet_id) : '',
        notes: settlement.notes ?? '',
    });
    const wallet = wallets.find((w) => w.id === settlement.wallet_id);
    const chosenWallet = wallets.find(
        (w) => String(w.id) === form.data.wallet_id,
    );

    // A new payout currency brings new default rates and maybe another wallet.
    useEffect(() => {
        form.setData((data) => ({
            ...data,
            rates: Object.fromEntries(
                currencies.map((c) => [c, settlement.rates[c] ?? '']),
            ),
            wallet_id: settlement.wallet_id ? String(settlement.wallet_id) : '',
        }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [settlement.payout_currency]);

    const changeCurrency = (currency: string) =>
        router.put(
            admin.settlements.update.url(settlement.id),
            {
                payout_currency: currency,
                wallet_id: form.data.wallet_id || null,
                notes: form.data.notes,
            },
            { preserveScroll: true },
        );

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
                            {settlement.client ? (
                                <span>
                                    {settlement.client.name}{' '}
                                    <span className="text-muted-foreground">
                                        · all companies of the client
                                    </span>
                                </span>
                            ) : (
                                <Link
                                    href={admin.merchants.show(
                                        settlement.merchant.public_id,
                                    )}
                                    className="hover:underline"
                                >
                                    {settlement.merchant.name}
                                </Link>
                            )}
                            <span>· {settlement.product_label}</span>
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
                                <Button onClick={() => setDialog('approve')}>
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
                {draft && <DueSummary lines={lines} />}
                <SettlementParties
                    overview={overview}
                    payoutCurrency={settlement.payout_currency}
                    product={settlement.product_label}
                />

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
                                                {line.owner && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {line.owner}
                                                    </div>
                                                )}
                                                <DueBadge due={line.due} />
                                                {line.type === 'report' &&
                                                    line.due === null && (
                                                        <span className="text-xs text-muted-foreground">
                                                            No payout schedule
                                                        </span>
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
                                    {currencyTotals.map((total, index) => (
                                        <TableRow
                                            key={total.currency}
                                            className={
                                                index === 0
                                                    ? 'border-t-2 bg-muted/30 hover:bg-muted/30'
                                                    : 'bg-muted/30 hover:bg-muted/30'
                                            }
                                        >
                                            <TableCell className="font-medium">
                                                Total {total.currency}
                                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                    {total.lines}{' '}
                                                    {total.lines === 1
                                                        ? 'line'
                                                        : 'lines'}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    total.amount,
                                                    total.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {total.rate || '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {formatMoney(total.payout)}
                                            </TableCell>
                                            {draft && <TableCell />}
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
                                <Field label="Payout currency">
                                    <Select
                                        value={settlement.payout_currency}
                                        onValueChange={changeCurrency}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {payoutCurrencies.map((c) => (
                                                <SelectItem
                                                    key={c.value}
                                                    value={c.value}
                                                >
                                                    {c.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
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
                                                    {settlement.client &&
                                                        ` · ${w.merchant}`}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {chosenWallet &&
                                        chosenWallet.currency.toUpperCase() !==
                                            settlement.payout_currency && (
                                            <p className="text-xs text-amber-600">
                                                This wallet is for{' '}
                                                {chosenWallet.currency}, the
                                                payout is in{' '}
                                                {settlement.payout_currency}.
                                            </p>
                                        )}
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
                            <>
                                {settlement.status === 'approved' && (
                                    <PayoutDestination
                                        settlement={settlement}
                                        wallet={wallet}
                                    />
                                )}
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
                                            {formatDateTime(
                                                settlement.approved_at,
                                            )}{' '}
                                            · {settlement.approver}
                                        </p>
                                    )}
                                    {settlement.settled_at && (
                                        <p>
                                            <span className="text-muted-foreground">
                                                Paid:
                                            </span>{' '}
                                            {formatDateTime(
                                                settlement.settled_at,
                                            )}{' '}
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
                            </>
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
            {dialog === 'approve' && (
                <ApproveDialog
                    settlement={settlement}
                    wallet={wallet}
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
