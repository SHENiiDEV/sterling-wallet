import { router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    History,
    Landmark,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { AffixInput, Field } from '@/components/admin/form';
import { StatusBadge } from '@/components/admin/status-badge';
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
import type { Option, ProviderRef, StatusColor } from '@/types';

export type Acquirer = {
    id: number;
    provider_id: number;
    provider: string;
    status: string;
    status_label: string;
    limit: string | null;
    limit_currency: string;
    integration_status: string;
    integration_label: string;
    integration_effective: string;
    integration_effective_label: string;
    integration_auto: boolean;
    integration_last_at: string | null;
    status_since: string | null;
    status_note: string | null;
    stale_days: number | null;
    client_id: number | null;
    psp: string | null;
    mcc: string | null;
    notes: string | null;
};

export type ClientRef = { id: number; name: string };

export type HistoryEntry = {
    id: number;
    at: string | null;
    user: string | null;
    area: string;
    title: string;
    lines: string[];
};

export const acquirerStatusColors: Record<string, StatusColor> = {
    prepare_kyb: 'amber',
    update_kyb: 'amber',
    kyb_submitted: 'blue',
    start_onboarding: 'cyan',
    onboarding_in_process: 'blue',
    approved: 'cyan',
    active_mids: 'emerald',
    rejected: 'rose',
    rebrand: 'orange',
    closed: 'slate',
};

export const acquirerColor = (status: string): StatusColor =>
    acquirerStatusColors[status] ?? 'slate';

function AcquirerDialog({
    merchantId,
    acquirer,
    banks,
    clients,
    statuses,
    integrationStatuses,
    onClose,
}: {
    merchantId: string;
    acquirer: Acquirer | null;
    banks: ProviderRef[];
    clients: ClientRef[];
    statuses: Option[];
    integrationStatuses: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        provider_id: acquirer ? String(acquirer.provider_id) : '',
        status: acquirer?.status ?? 'prepare_kyb',
        limit: acquirer?.limit ? String(Number(acquirer.limit)) : '',
        limit_currency: acquirer?.limit_currency ?? 'EUR',
        integration_status: acquirer?.integration_status ?? 'need_to_do',
        client_id: acquirer?.client_id ? String(acquirer.client_id) : '',
        status_note: '',
        mcc: acquirer?.mcc ?? '',
        notes: acquirer?.notes ?? '',
    });
    const { data, setData, errors } = form;

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
                        if (acquirer) {
                            form.put(
                                admin.merchants.acquirers.update.url({
                                    merchant: merchantId,
                                    acquirer: acquirer.id,
                                }),
                                options,
                            );
                        } else {
                            form.post(
                                admin.merchants.acquirers.store.url(merchantId),
                                options,
                            );
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {acquirer ? acquirer.provider : 'Add bank'}
                        </DialogTitle>
                        <DialogDescription>
                            Where the merchant stands with this acquirer.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Bank" error={errors.provider_id}>
                            <Select
                                value={data.provider_id}
                                onValueChange={(v) => setData('provider_id', v)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {banks.map((b) => (
                                        <SelectItem
                                            key={b.id}
                                            value={String(b.id)}
                                        >
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Onboarding" error={errors.status}>
                            <Select
                                value={data.status}
                                onValueChange={(v) => setData('status', v)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {statuses.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Limit"
                            htmlFor="a-limit"
                            error={errors.limit}
                        >
                            <AffixInput
                                id="a-limit"
                                suffix={data.limit_currency}
                                inputMode="decimal"
                                value={data.limit}
                                onChange={(e) =>
                                    setData('limit', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Integration"
                            error={errors.integration_status}
                        >
                            <Select
                                value={data.integration_status}
                                onValueChange={(v) =>
                                    setData('integration_status', v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {integrationStatuses.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Client (PSP)" error={errors.client_id}>
                            <Select
                                value={data.client_id || 'none'}
                                onValueChange={(v) =>
                                    setData('client_id', v === 'none' ? '' : v)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        {acquirer?.psp && !acquirer.client_id
                                            ? `${acquirer.psp} (not a client yet)`
                                            : 'No client'}
                                    </SelectItem>
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
                        {acquirer && data.status !== acquirer.status && (
                            <Field
                                label="Why is the status changing?"
                                htmlFor="a-status-note"
                                error={errors.status_note}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="a-status-note"
                                    placeholder="e.g. re-brand: new site"
                                    value={data.status_note}
                                    onChange={(e) =>
                                        setData('status_note', e.target.value)
                                    }
                                />
                            </Field>
                        )}
                        <Field label="MCC" htmlFor="a-mcc" error={errors.mcc}>
                            <Input
                                id="a-mcc"
                                inputMode="numeric"
                                maxLength={4}
                                value={data.mcc}
                                onChange={(e) => setData('mcc', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="a-notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="a-notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
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
                        <Button
                            type="submit"
                            disabled={form.processing || !data.provider_id}
                        >
                            {form.processing && <Spinner />}
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function AcquirersSection({
    merchantId,
    acquirers,
    banks,
    clients,
    history,
    statuses,
    integrationStatuses,
}: {
    merchantId: string;
    acquirers: Acquirer[];
    banks: ProviderRef[];
    clients: ClientRef[];
    history: HistoryEntry[];
    statuses: Option[];
    integrationStatuses: Option[];
}) {
    const [editing, setEditing] = useState<Acquirer | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Acquirer | null>(null);

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <Landmark className="size-4 text-muted-foreground" />
                        Banks & onboarding
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        KYB status, approved limit and integration with each
                        acquirer.
                    </p>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing('new')}
                >
                    <Plus />
                    Add bank
                </Button>
            </header>
            {acquirers.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    No banks yet.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-5">Bank</TableHead>
                            <TableHead>Onboarding</TableHead>
                            <TableHead className="text-right">Limit</TableHead>
                            <TableHead className="hidden md:table-cell">
                                Integration
                            </TableHead>
                            <TableHead className="hidden md:table-cell">
                                MCC
                            </TableHead>
                            <TableHead className="hidden md:table-cell">
                                PSP
                            </TableHead>
                            <TableHead className="w-24 pr-5" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {acquirers.map((a) => (
                            <TableRow key={a.id}>
                                <TableCell className="pl-5 font-medium">
                                    {a.provider}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        name={a.status_label}
                                        color={acquirerColor(a.status)}
                                    />
                                    {a.stale_days !== null && (
                                        <span className="mt-1 flex items-center gap-1 text-xs font-medium text-amber-600">
                                            <AlertTriangle className="size-3.5" />
                                            {a.stale_days} days in this status
                                        </span>
                                    )}
                                    {a.status_since && (
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            since {formatDate(a.status_since)}
                                            {a.status_note &&
                                                ` · ${a.status_note}`}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {a.limit
                                        ? formatMoney(
                                              a.limit,
                                              a.limit_currency,
                                              0,
                                          )
                                        : '—'}
                                </TableCell>
                                <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                    {a.integration_effective_label}
                                    {a.integration_auto && (
                                        <span
                                            className="block text-xs text-emerald-600"
                                            title="The gateway has reported payments on this merchant's terminals at this bank"
                                        >
                                            live · last payment{' '}
                                            {formatDate(a.integration_last_at)}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="hidden text-sm tabular-nums md:table-cell">
                                    {a.mcc ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="hidden text-sm md:table-cell">
                                    {(a.client_id
                                        ? clients.find(
                                              (c) => c.id === a.client_id,
                                          )?.name
                                        : a.psp) ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="pr-5">
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Edit"
                                            onClick={() => setEditing(a)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Remove"
                                            onClick={() => setDeleting(a)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {editing && (
                <AcquirerDialog
                    merchantId={merchantId}
                    acquirer={editing === 'new' ? null : editing}
                    banks={banks}
                    clients={clients}
                    statuses={statuses}
                    integrationStatuses={integrationStatuses}
                    onClose={() => setEditing(null)}
                />
            )}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Remove ${deleting?.provider ?? 'bank'}?`}
                description="Only the onboarding record is removed; MIDs are not touched."
                onConfirm={() =>
                    deleting &&
                    router.delete(
                        admin.merchants.acquirers.destroy.url({
                            merchant: merchantId,
                            acquirer: deleting.id,
                        }),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        },
                    )
                }
            />
            <HistoryList history={history} />
        </section>
    );
}

function HistoryList({ history }: { history: HistoryEntry[] }) {
    return (
        <div className="border-t px-5 py-4">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <History className="size-4 text-muted-foreground" />
                History
            </h3>
            {history.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Changes to banks and MIDs will be listed here.
                </p>
            ) : (
                <ul className="space-y-3">
                    {history.map((entry) => (
                        <li key={entry.id} className="text-sm">
                            <div className="flex flex-wrap items-baseline gap-x-2">
                                <span className="font-medium">
                                    {entry.title}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(entry.at)}
                                    {entry.user && ` · ${entry.user}`}
                                </span>
                            </div>
                            {entry.lines.map((line) => (
                                <div
                                    key={line}
                                    className="text-xs text-muted-foreground"
                                >
                                    {line}
                                </div>
                            ))}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
