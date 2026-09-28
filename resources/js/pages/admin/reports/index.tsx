import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Upload } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type { Option } from '@/types';

type Cell = {
    id: number | null;
    status: string;
    from: string;
    to: string;
    reason: string | null;
    missing: string[];
    turnover: string | null;
    net_profit: string | null;
};

type Row = {
    id: number;
    mid: string;
    label: string | null;
    currency: string;
    status: string;
    merchant: { public_id: string; name: string };
    pair: string;
    cells: Record<string, Cell>;
};

type Total = {
    currency: string;
    reports: number;
    turnover: number;
    payout: number;
    paid_out: number;
    our_fee: number;
    net_profit: number;
    reserve: number;
};

type Props = {
    month: string;
    days: number;
    today: string;
    rows: Row[];
    totals: Total[];
    statusCounts: Record<string, number>;
    filters: {
        month?: string;
        merchant?: string;
        currency?: string;
        status?: string;
    };
    merchants: { public_id: string; name: string }[];
    statuses: Option[];
    providers: { id: number; name: string; type: string }[];
};

const ALL = 'all';

/** Status → cell look. Shape and label carry meaning too, not colour alone. */
const statusStyle: Record<
    string,
    { cell: string; line: string; label: string }
> = {
    completed: {
        cell: 'bg-success text-white',
        line: 'bg-success/50',
        label: 'Completed',
    },
    pending: {
        cell: 'bg-slate-400 text-white',
        line: 'bg-slate-400/50',
        label: 'Pending',
    },
    partial: {
        cell: 'bg-warning text-white',
        line: 'bg-warning/50',
        label: 'Partial',
    },
    blocked: {
        cell: 'bg-rose-500 text-white',
        line: 'bg-rose-500/50',
        label: 'Blocked',
    },
    failed: {
        cell: 'bg-rose-800 text-white',
        line: 'bg-rose-800/50',
        label: 'Failed',
    },
    missing: {
        cell: 'border-2 border-dashed border-warning bg-transparent text-warning',
        line: 'bg-warning/30',
        label: 'Missing file',
    },
};

const statusMark: Record<string, string> = {
    completed: '✓',
    pending: '…',
    partial: '½',
    blocked: '!',
    failed: '×',
    missing: '?',
};

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function UploadDialog({
    providers,
    onClose,
}: {
    providers: Props['providers'];
    onClose: () => void;
}) {
    const form = useForm<{
        provider_id: string;
        report_date: string;
        file: File | null;
    }>({
        provider_id: providers[0] ? String(providers[0].id) : '',
        report_date: '',
        file: null,
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(admin.reports.upload.url(), {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Upload a provider file</DialogTitle>
                        <DialogDescription>
                            Same pipeline as the bots: the file is split by MID,
                            operations are imported and reports are calculated
                            once every source of a MID is in.
                        </DialogDescription>
                    </DialogHeader>
                    <Field label="Provider" error={form.errors.provider_id}>
                        <Select
                            value={form.data.provider_id}
                            onValueChange={(v) =>
                                form.setData('provider_id', v)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose…" />
                            </SelectTrigger>
                            <SelectContent>
                                {providers.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.name}{' '}
                                        <span className="text-muted-foreground">
                                            (
                                            {p.type === 'bank'
                                                ? 'acquirer'
                                                : 'gateway'}
                                            )
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Report date"
                        htmlFor="report_date"
                        error={form.errors.report_date}
                        hint="Only needed when the file doesn't state its date"
                    >
                        <Input
                            id="report_date"
                            type="date"
                            value={form.data.report_date}
                            onChange={(e) =>
                                form.setData('report_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="File (CSV or XLSX)"
                        htmlFor="file"
                        error={form.errors.file}
                    >
                        <Input
                            id="file"
                            type="file"
                            accept=".csv,.xlsx,.xls,.txt"
                            onChange={(e) =>
                                form.setData(
                                    'file',
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
                            disabled={form.processing || !form.data.file}
                        >
                            {form.processing ? <Spinner /> : <Upload />}
                            Import
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ReportsIndex({
    month,
    days,
    today,
    rows,
    totals,
    statusCounts,
    filters,
    merchants,
    statuses,
    providers,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const apply = (next: Partial<Props['filters']>) =>
        router.get(
            admin.reports.index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, month, ...next }).filter(
                    ([, v]) => v,
                ),
            ),
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const dates = Array.from(
        { length: days },
        (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`,
    );
    const monthLabel = new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${month}-01T12:00:00`));

    /** Days inside a multi-day period (Fri–Sun, holidays) point at their report. */
    const covering = (row: Row) => {
        const map: Record<string, Cell> = {};
        Object.values(row.cells).forEach((cell) => {
            for (const date of dates) {
                if (date >= cell.from && date < cell.to) {
                    map[date] = cell;
                }
            }
        });

        return map;
    };

    return (
        <>
            <Head title="Report Control Center" />
            <PageBody>
                <PageHeader
                    title="Report Control Center"
                    description="Every MID × every report day. Weekend and holiday days are joined into one report on the last day of the period."
                    actions={
                        <Button onClick={() => setUploading(true)}>
                            <Upload />
                            Upload file
                        </Button>
                    }
                />

                <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex items-center gap-2">
                        <Button
                            size="icon"
                            variant="outline"
                            onClick={() =>
                                apply({ month: shiftMonth(month, -1) })
                            }
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
                            onClick={() =>
                                apply({ month: shiftMonth(month, 1) })
                            }
                            aria-label="Next month"
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                    <div className="flex flex-col gap-2 md:flex-row">
                        <Select
                            value={filters.merchant ?? ALL}
                            onValueChange={(v) =>
                                apply({ merchant: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-56">
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
                        <Select
                            value={filters.currency ?? ALL}
                            onValueChange={(v) =>
                                apply({ currency: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    Any currency
                                </SelectItem>
                                {['EUR', 'USD', 'GBP'].map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {c}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.status ?? ALL}
                            onValueChange={(v) =>
                                apply({ status: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any status</SelectItem>
                                {statuses.map((s) => (
                                    <SelectItem key={s.value} value={s.value}>
                                        {s.label} · {statusCounts[s.value] ?? 0}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {totals.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {totals.map((t) => (
                            <div
                                key={t.currency}
                                className="rounded-xl border bg-card p-4 shadow-xs"
                            >
                                <div className="flex items-center justify-between">
                                    <CurrencyBadge currency={t.currency} />
                                    <span className="text-xs text-muted-foreground">
                                        {t.reports} completed report(s)
                                    </span>
                                </div>
                                <dl className="mt-2 grid grid-cols-2 gap-y-1 text-sm">
                                    <dt className="text-muted-foreground">
                                        Turnover
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.turnover, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        To pay out
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.payout, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Paid out
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.paid_out, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Our fees
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.our_fee, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Net profit
                                    </dt>
                                    <dd className="text-right font-medium tabular-nums">
                                        {formatMoney(t.net_profit, t.currency)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Reserve held
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(t.reserve, t.currency)}
                                    </dd>
                                </dl>
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {Object.entries(statusStyle).map(([key, style]) => (
                        <span
                            key={key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'inline-flex size-4 items-center justify-center rounded text-[10px] font-bold',
                                    style.cell,
                                )}
                            >
                                {statusMark[key]}
                            </span>
                            {style.label}
                        </span>
                    ))}
                    <span className="inline-flex items-center gap-1.5">
                        <span className="h-1 w-4 rounded-full bg-muted-foreground/40" />
                        Day included in the next report
                    </span>
                </div>

                <div className="overflow-x-auto rounded-xl border bg-card shadow-xs">
                    {rows.length === 0 ? (
                        <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                            No MIDs match these filters.
                        </p>
                    ) : (
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th className="sticky left-0 z-10 min-w-56 bg-card px-3 py-2 text-left font-medium">
                                        MID
                                    </th>
                                    {dates.map((date, i) => {
                                        const d = new Date(`${date}T12:00:00`);
                                        const weekend =
                                            d.getDay() === 0 ||
                                            d.getDay() === 6;

                                        return (
                                            <th
                                                key={date}
                                                className={cn(
                                                    'w-7 min-w-7 px-0 py-2 text-center text-[11px] font-medium',
                                                    weekend
                                                        ? 'text-muted-foreground/60'
                                                        : 'text-muted-foreground',
                                                    date === today &&
                                                        'text-foreground underline',
                                                )}
                                            >
                                                {i + 1}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const covered = covering(row);

                                    return (
                                        <tr
                                            key={row.id}
                                            className="border-b last:border-0 hover:bg-muted/30"
                                        >
                                            <td className="sticky left-0 z-10 bg-card px-3 py-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono text-xs">
                                                        {row.mid}
                                                    </span>
                                                    <CurrencyBadge
                                                        currency={row.currency}
                                                    />
                                                </div>
                                                <Link
                                                    href={admin.merchants.show(
                                                        row.merchant.public_id,
                                                    )}
                                                    className="block max-w-56 truncate text-xs text-muted-foreground hover:underline"
                                                >
                                                    {row.merchant.name} ·{' '}
                                                    {row.pair}
                                                </Link>
                                            </td>
                                            {dates.map((date) => {
                                                const cell = row.cells[date];
                                                const inPeriod = covered[date];

                                                if (cell) {
                                                    const style =
                                                        statusStyle[
                                                            cell.status
                                                        ] ??
                                                        statusStyle.pending;
                                                    const title = [
                                                        `${style.label} · ${cell.from === cell.to ? cell.to : `${cell.from} → ${cell.to}`}`,
                                                        cell.missing.length
                                                            ? `Waiting for: ${cell.missing.join(', ')}`
                                                            : null,
                                                        cell.reason,
                                                        cell.turnover !== null
                                                            ? `Turnover ${formatMoney(cell.turnover, row.currency)} · profit ${formatMoney(cell.net_profit, row.currency)}`
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join('\n');
                                                    const mark = (
                                                        <span
                                                            className={cn(
                                                                'mx-auto flex size-5 items-center justify-center rounded text-[10px] font-bold',
                                                                style.cell,
                                                            )}
                                                        >
                                                            {statusMark[
                                                                cell.status
                                                            ] ?? '•'}
                                                        </span>
                                                    );

                                                    return (
                                                        <td
                                                            key={date}
                                                            className="px-0 py-2 text-center"
                                                            title={title}
                                                        >
                                                            {cell.id ? (
                                                                <Link
                                                                    href={admin.reports.show(
                                                                        cell.id,
                                                                    )}
                                                                    aria-label={
                                                                        title
                                                                    }
                                                                >
                                                                    {mark}
                                                                </Link>
                                                            ) : (
                                                                mark
                                                            )}
                                                        </td>
                                                    );
                                                }

                                                if (inPeriod) {
                                                    const style =
                                                        statusStyle[
                                                            inPeriod.status
                                                        ] ??
                                                        statusStyle.pending;

                                                    return (
                                                        <td
                                                            key={date}
                                                            className="px-0 py-2"
                                                            title={`Included in the ${inPeriod.to} report`}
                                                        >
                                                            <span
                                                                className={cn(
                                                                    'block h-1 w-full',
                                                                    style.line,
                                                                )}
                                                            />
                                                        </td>
                                                    );
                                                }

                                                return <td key={date} />;
                                            })}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </PageBody>
            {uploading && (
                <UploadDialog
                    providers={providers}
                    onClose={() => setUploading(false)}
                />
            )}
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        { title: 'Report Control Center', href: admin.reports.index() },
    ],
};
