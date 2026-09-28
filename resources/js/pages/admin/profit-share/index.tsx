import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Download,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
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
import type { Option } from '@/types';

type Rule = {
    id: number;
    merchant_id: number | null;
    merchant: string | null;
    base: string;
    percent: string;
    valid_from: string;
    valid_to: string | null;
    notes: string | null;
};

type Partner = {
    id: number;
    name: string;
    email: string | null;
    is_active: boolean;
    notes: string | null;
    rules: Rule[];
};

type Props = {
    month: string;
    baseCurrency: string;
    statement: {
        id: number;
        month: string;
        status: 'draft' | 'closed';
        turnover: string;
        net_profit: string;
        shares_total: string;
        company_remainder: string;
        merchants: {
            merchant_id: number;
            name: string;
            turnover: string;
            net_profit: string;
        }[];
        closed_at: string | null;
        closer: string | null;
        calculated_at: string | null;
        lines: {
            id: number;
            partner: string;
            partner_id: number | null;
            merchant: string;
            base: string;
            base_amount: string;
            percent: string;
            share: string;
        }[];
    };
    statements: {
        month: string;
        status: string;
        net_profit: string;
        shares_total: string;
        company_remainder: string;
    }[];
    partners: Partner[];
    merchants: { id: number; name: string }[];
    bases: Option[];
};

const DEFAULT = 'default';

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function PartnerDialog({
    partner,
    onClose,
}: {
    partner: Partner | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: partner?.name ?? '',
        email: partner?.email ?? '',
        is_active: partner?.is_active ?? true,
        notes: partner?.notes ?? '',
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: onClose,
                        };
                        if (partner) {
                            form.put(
                                admin.profitShare.partners.update.url(
                                    partner.id,
                                ),
                                options,
                            );
                        } else {
                            form.post(
                                admin.profitShare.partners.store.url(),
                                options,
                            );
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {partner ? partner.name : 'New partner'}
                        </DialogTitle>
                        <DialogDescription>
                            Someone who receives a share of profit or turnover.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Name"
                        htmlFor="p-name"
                        error={form.errors.name}
                    >
                        <Input
                            id="p-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            autoFocus
                        />
                    </Field>
                    <Field
                        label="E-mail"
                        htmlFor="p-email"
                        error={form.errors.email}
                    >
                        <Input
                            id="p-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Notes"
                        htmlFor="p-notes"
                        error={form.errors.notes}
                    >
                        <Textarea
                            id="p-notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-3 text-sm">
                        <Checkbox
                            checked={form.data.is_active}
                            onCheckedChange={(c) =>
                                form.setData('is_active', c === true)
                            }
                        />
                        Active — included in statements
                    </label>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RuleDialog({
    partner,
    rule,
    merchants,
    bases,
    onClose,
}: {
    partner: Partner;
    rule: Rule | null;
    merchants: Props['merchants'];
    bases: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        merchant_id: rule?.merchant_id ? String(rule.merchant_id) : '',
        base: rule?.base ?? 'net_profit',
        percent: rule ? String(Number(rule.percent)) : '',
        valid_from:
            rule?.valid_from ?? new Date().toISOString().slice(0, 8) + '01',
        valid_to: rule?.valid_to ?? '',
        notes: rule?.notes ?? '',
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: onClose,
                        };
                        if (rule) {
                            form.put(
                                admin.profitShare.rules.update.url(rule.id),
                                options,
                            );
                        } else {
                            form.post(
                                admin.profitShare.rules.store.url(partner.id),
                                options,
                            );
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {rule ? 'Edit rule' : 'New rule'} · {partner.name}
                        </DialogTitle>
                        <DialogDescription>
                            A rule for one merchant beats the partner's default
                            rule for that merchant.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Merchant"
                            error={form.errors.merchant_id}
                            className="sm:col-span-2"
                        >
                            <Select
                                value={form.data.merchant_id || DEFAULT}
                                onValueChange={(v) =>
                                    form.setData(
                                        'merchant_id',
                                        v === DEFAULT ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={DEFAULT}>
                                        Default — every merchant
                                    </SelectItem>
                                    {merchants.map((m) => (
                                        <SelectItem
                                            key={m.id}
                                            value={String(m.id)}
                                        >
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Share of" error={form.errors.base}>
                            <Select
                                value={form.data.base}
                                onValueChange={(v) => form.setData('base', v)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {bases.map((b) => (
                                        <SelectItem
                                            key={b.value}
                                            value={b.value}
                                        >
                                            {b.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Percent"
                            htmlFor="r-percent"
                            error={form.errors.percent}
                        >
                            <Input
                                id="r-percent"
                                inputMode="decimal"
                                value={form.data.percent}
                                onChange={(e) =>
                                    form.setData('percent', e.target.value)
                                }
                                placeholder="20"
                            />
                        </Field>
                        <Field
                            label="Valid from"
                            htmlFor="r-from"
                            error={form.errors.valid_from}
                        >
                            <Input
                                id="r-from"
                                type="date"
                                value={form.data.valid_from}
                                onChange={(e) =>
                                    form.setData('valid_from', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Valid to"
                            htmlFor="r-to"
                            error={form.errors.valid_to}
                            hint="Empty = open-ended"
                        >
                            <Input
                                id="r-to"
                                type="date"
                                value={form.data.valid_to}
                                onChange={(e) =>
                                    form.setData('valid_to', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="r-notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="r-notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
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
                            Save rule
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ProfitShareIndex({
    month,
    baseCurrency,
    statement,
    statements,
    partners,
    merchants,
    bases,
}: Props) {
    const [editingPartner, setEditingPartner] = useState<
        Partner | 'new' | null
    >(null);
    const [editingRule, setEditingRule] = useState<{
        partner: Partner;
        rule: Rule | null;
    } | null>(null);
    const [deleting, setDeleting] = useState<{
        kind: 'partner' | 'rule';
        id: number;
        name: string;
    } | null>(null);
    const [closing, setClosing] = useState(false);
    const closed = statement.status === 'closed';
    const negative = Number(statement.company_remainder) < 0;
    const money = (v: string | number) => formatMoney(v, baseCurrency);
    const go = (m: string) =>
        router.get(
            admin.profitShare.index.url(),
            { month: m },
            { preserveScroll: true, replace: true },
        );
    const monthLabel = new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${month}-01T12:00:00`));

    return (
        <>
            <Head title="Profit share" />
            <PageBody>
                <PageHeader
                    title="Profit share"
                    description={`Partner shares per month from completed daily reports, in ${baseCurrency}. A closed month is frozen.`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={admin.profitShare.pdf.url(month)}>
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                            {closed ? (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            admin.profitShare.reopen.url(month),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <LockOpen />
                                    Reopen
                                </Button>
                            ) : (
                                <Button onClick={() => setClosing(true)}>
                                    <Lock />
                                    Close month
                                </Button>
                            )}
                        </>
                    }
                />
                <PageErrors keys={['statement']} />

                <div className="flex items-center gap-2">
                    <Button
                        size="icon"
                        variant="outline"
                        onClick={() => go(shiftMonth(month, -1))}
                        aria-label="Previous month"
                    >
                        <ChevronLeft />
                    </Button>
                    <span className="w-40 text-center font-semibold">
                        {monthLabel}
                    </span>
                    <Button
                        size="icon"
                        variant="outline"
                        onClick={() => go(shiftMonth(month, 1))}
                        aria-label="Next month"
                    >
                        <ChevronRight />
                    </Button>
                    <StatusBadge
                        name={closed ? 'Closed' : 'Draft'}
                        color={closed ? 'emerald' : 'slate'}
                    />
                    <span className="text-xs text-muted-foreground">
                        {closed
                            ? `closed ${formatDateTime(statement.closed_at)} by ${statement.closer ?? '—'}`
                            : `calculated ${formatDateTime(statement.calculated_at)}`}
                    </span>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        ['Turnover', statement.turnover],
                        ['Net profit', statement.net_profit],
                        ['Partner shares', statement.shares_total],
                        ['Company remainder', statement.company_remainder],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className={cn(
                                'rounded-xl border bg-card p-5 shadow-xs',
                                label === 'Company remainder' &&
                                    negative &&
                                    'border-destructive/50',
                            )}
                        >
                            <p className="text-sm font-medium text-muted-foreground">
                                {label}
                            </p>
                            <p className="mt-2 text-2xl font-semibold tabular-nums">
                                {money(value)}
                            </p>
                        </div>
                    ))}
                </div>
                {negative && (
                    <div className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                        <AlertTriangle className="size-4 text-destructive-foreground" />
                        Shares exceed net profit this month: the company
                        remainder is negative.
                    </div>
                )}

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="text-sm font-semibold">
                            Shares · {monthLabel}
                        </h2>
                    </header>
                    {statement.lines.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No rules apply to this month's reports.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Partner</TableHead>
                                    <TableHead>Merchant</TableHead>
                                    <TableHead>Base</TableHead>
                                    <TableHead className="text-right">
                                        Base amount
                                    </TableHead>
                                    <TableHead className="text-right">
                                        %
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Share
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {statement.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="font-medium">
                                            {line.partner}
                                        </TableCell>
                                        <TableCell>{line.merchant}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {line.base === 'turnover'
                                                ? 'Turnover'
                                                : 'Net profit'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(line.base_amount)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(line.percent)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {money(line.share)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="flex items-center justify-between border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">
                            Partners & rules
                        </h2>
                        <Button
                            size="sm"
                            onClick={() => setEditingPartner('new')}
                        >
                            <Plus />
                            Partner
                        </Button>
                    </header>
                    {partners.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Add a partner, then give them a rule.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {partners.map((partner) => (
                                <div key={partner.id} className="px-5 py-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <span className="font-medium">
                                                {partner.name}
                                            </span>
                                            {!partner.is_active && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    (inactive)
                                                </span>
                                            )}
                                            {partner.email && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {partner.email}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditingRule({
                                                        partner,
                                                        rule: null,
                                                    })
                                                }
                                            >
                                                <Plus />
                                                Rule
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Edit partner"
                                                onClick={() =>
                                                    setEditingPartner(partner)
                                                }
                                            >
                                                <Pencil />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Delete partner"
                                                onClick={() =>
                                                    setDeleting({
                                                        kind: 'partner',
                                                        id: partner.id,
                                                        name: partner.name,
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>
                                    {partner.rules.length > 0 && (
                                        <ul className="mt-2 grid gap-1 text-sm">
                                            {partner.rules.map((rule) => (
                                                <li
                                                    key={rule.id}
                                                    className="flex flex-wrap items-center gap-2 rounded-md bg-muted/40 px-3 py-1.5"
                                                >
                                                    <span className="font-medium tabular-nums">
                                                        {Number(rule.percent)}%
                                                    </span>
                                                    <span>
                                                        of{' '}
                                                        {rule.base ===
                                                        'turnover'
                                                            ? 'turnover'
                                                            : 'net profit'}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        ·{' '}
                                                        {rule.merchant ??
                                                            'every merchant'}
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        ·{' '}
                                                        {formatDate(
                                                            rule.valid_from,
                                                        )}{' '}
                                                        —{' '}
                                                        {rule.valid_to
                                                            ? formatDate(
                                                                  rule.valid_to,
                                                              )
                                                            : 'open'}
                                                    </span>
                                                    <span className="ml-auto flex gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            aria-label="Edit rule"
                                                            onClick={() =>
                                                                setEditingRule({
                                                                    partner,
                                                                    rule,
                                                                })
                                                            }
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            aria-label="Delete rule"
                                                            onClick={() =>
                                                                setDeleting({
                                                                    kind: 'rule',
                                                                    id: rule.id,
                                                                    name: `${Number(rule.percent)}% rule`,
                                                                })
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {statements.length > 0 && (
                    <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <header className="border-b px-5 py-3.5">
                            <h2 className="text-sm font-semibold">Months</h2>
                        </header>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Month</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Net profit
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Shares
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Remainder
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {statements.map((s) => (
                                    <TableRow
                                        key={s.month}
                                        className="cursor-pointer"
                                        onClick={() => go(s.month)}
                                    >
                                        <TableCell className="font-medium">
                                            {s.month}
                                        </TableCell>
                                        <TableCell className="capitalize">
                                            {s.status}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(s.net_profit)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(s.shares_total)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right tabular-nums',
                                                Number(s.company_remainder) <
                                                    0 &&
                                                    'font-semibold text-destructive-foreground',
                                            )}
                                        >
                                            {money(s.company_remainder)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}
            </PageBody>

            {editingPartner && (
                <PartnerDialog
                    partner={editingPartner === 'new' ? null : editingPartner}
                    onClose={() => setEditingPartner(null)}
                />
            )}
            {editingRule && (
                <RuleDialog
                    partner={editingRule.partner}
                    rule={editingRule.rule}
                    merchants={merchants}
                    bases={bases}
                    onClose={() => setEditingRule(null)}
                />
            )}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? ''}?`}
                description="Closed months keep their figures; open months are recalculated."
                onConfirm={() =>
                    deleting &&
                    router.delete(
                        deleting.kind === 'partner'
                            ? admin.profitShare.partners.destroy.url(
                                  deleting.id,
                              )
                            : admin.profitShare.rules.destroy.url(deleting.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        },
                    )
                }
            />
            <ConfirmDialog
                open={closing}
                onOpenChange={setClosing}
                title={`Close ${monthLabel}?`}
                description="The figures are recalculated one last time and then frozen. Later report changes won't affect this month."
                confirmLabel="Close month"
                destructive={false}
                onConfirm={() =>
                    router.post(
                        admin.profitShare.close.url(month),
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => setClosing(false),
                        },
                    )
                }
            />
        </>
    );
}

ProfitShareIndex.layout = {
    breadcrumbs: [{ title: 'Profit share', href: admin.profitShare.index() }],
};
