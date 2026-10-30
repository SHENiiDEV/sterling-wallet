import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Download,
    Link2Off,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import {
    CardLabel,
    OperationTypeBadge,
    RoleLabel,
} from '@/components/admin/operation-badges';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Field } from '@/components/admin/form';
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
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type {
    Operation,
    OperationTotal,
    Option,
    Paginated,
    ProviderRef,
} from '@/types';

type Filters = {
    search?: string;
    provider?: string;
    role?: string;
    type?: string;
    region?: string;
    currency?: string;
    from?: string;
    to?: string;
    unmatched?: string;
    client?: string;
    company?: string;
    mid?: string;
};

type Props = {
    operations: Paginated<Operation>;
    totals: OperationTotal[];
    filters: Filters;
    providers: ProviderRef[];
    clients: { id: number; name: string }[];
    companies: {
        id: number;
        name: string;
        client_id: number | null;
        parent_id: number | null;
    }[];
    mids: {
        id: number;
        mid: string;
        currency: string;
        merchant: string | null;
        company_id: number | null;
    }[];
    roles: Option[];
    types: Option[];
    currencies: Option[];
};

const ALL = 'all';

/** Deleting several operations: the ticked ones, or everything the filters match. */
function BulkDeleteDialog({
    count,
    mode,
    ids,
    filters,
    onClose,
}: {
    count: number;
    mode: 'selected' | 'filtered';
    ids: number[];
    filters: Filters;
    onClose: () => void;
}) {
    const [confirm, setConfirm] = useState('');
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const phrase = `DELETE ${count}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            admin.operations.bulkDestroy.url(),
                            {
                                mode,
                                ...(mode === 'selected' ? { ids } : filters),
                                expected: count,
                                confirm,
                                reason,
                            },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: setErrors,
                                onSuccess: onClose,
                            },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Delete {count} operation{count === 1 ? '' : 's'}?
                        </DialogTitle>
                        <DialogDescription>
                            {mode === 'selected'
                                ? 'The ticked operations are removed for good.'
                                : 'Everything the current filters match is removed for good, also on other pages.'}{' '}
                            Reports already built for those days are not
                            recalculated: regenerate them afterwards. Operations
                            in a report that is already paid out are kept.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label={`Type “${phrase}” to confirm`}
                        htmlFor="bulk-confirm"
                        error={errors.confirm ?? errors.expected ?? errors.mode}
                    >
                        <Input
                            id="bulk-confirm"
                            autoComplete="off"
                            value={confirm}
                            onChange={(e) => setConfirm(e.target.value)}
                        />
                    </Field>
                    <Field
                        label="Reason"
                        htmlFor="bulk-reason"
                        error={errors.reason}
                    >
                        <Input
                            id="bulk-reason"
                            placeholder="Why these operations are removed"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
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
                            variant="destructive"
                            disabled={
                                processing ||
                                confirm.trim() !== phrase ||
                                reason.trim() === ''
                            }
                        >
                            {processing ? <Spinner /> : <Trash2 />}
                            Delete
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OperationsIndex({
    operations,
    totals,
    filters,
    providers,
    clients,
    companies,
    mids,
    roles,
    types,
    currencies,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    const apply = (next: Filters) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );
        router.get(admin.operations.index.url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timeout = setTimeout(() => apply({ search }), 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Object.values(filters).some(Boolean);

    // Ticking rows: this page's, or "everything the filters match" (only when the list is filtered).
    const [selected, setSelected] = useState<number[]>([]);
    const [allMatching, setAllMatching] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const pageIds = operations.data.map((op) => op.id);
    const allOnPage =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const selectedCount = allMatching ? operations.total : selected.length;
    const filtersKey = JSON.stringify(filters);
    useEffect(() => {
        setSelected([]);
        setAllMatching(false);
    }, [operations.current_page, filtersKey]);
    const toggleRow = (id: number, on: boolean) => {
        setAllMatching(false);
        setSelected((current) =>
            on
                ? [...new Set([...current, id])]
                : current.filter((x) => x !== id),
        );
    };
    const unmatched = filters.unmatched === '1';

    const select = (
        key: keyof Filters,
        placeholder: string,
        options: Option[],
        width = 'md:w-40',
    ) => (
        <Select
            value={filters[key] ?? ALL}
            onValueChange={(value) =>
                apply({ [key]: value === ALL ? '' : value })
            }
        >
            <SelectTrigger className={cn('w-full', width)}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    // Client → company (and its sub-companies) → terminal: each list narrows the next.
    const companyOptions = companies.filter(
        (c) => !filters.client || String(c.client_id) === filters.client,
    );
    const inCompany = (companyId: number | null) => {
        if (!filters.company) {
            return true;
        }

        const wanted = new Set([Number(filters.company)]);
        let grew = true;

        while (grew) {
            grew = false;

            for (const c of companies) {
                if (
                    c.parent_id !== null &&
                    wanted.has(c.parent_id) &&
                    !wanted.has(c.id)
                ) {
                    wanted.add(c.id);
                    grew = true;
                }
            }
        }

        return companyId !== null && wanted.has(companyId);
    };
    const clientOf = (companyId: number | null) =>
        companies.find((c) => c.id === companyId)?.client_id;
    const midOptions = mids.filter(
        (m) =>
            inCompany(m.company_id) &&
            (!filters.client ||
                String(clientOf(m.company_id)) === filters.client),
    );
    const cascade = (
        key: 'client' | 'company' | 'mid',
        placeholder: string,
        options: Option[],
        reset: (keyof Filters)[],
    ) => (
        <Select
            value={filters[key] ?? ALL}
            onValueChange={(value) =>
                apply({
                    [key]: value === ALL ? '' : value,
                    ...Object.fromEntries(reset.map((k) => [k, ''])),
                })
            }
        >
            <SelectTrigger className="w-full md:w-60">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    const byCurrency = totals.reduce<Record<string, OperationTotal[]>>(
        (groups, total) => {
            (groups[total.currency] ??= []).push(total);

            return groups;
        },
        {},
    );

    return (
        <>
            <Head title="Operations" />
            <PageBody>
                <PageHeader
                    title="Operations"
                    description="Every operation from every provider file. Acquirer and gateway rows are paired by reconciliation."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a
                                    href={admin.operations.export.url({
                                        query: Object.fromEntries(
                                            Object.entries(filters).filter(
                                                ([, v]) => v,
                                            ),
                                        ),
                                    })}
                                >
                                    <Download />
                                    Export CSV
                                </a>
                            </Button>
                            <Button
                                variant={unmatched ? 'default' : 'outline'}
                                onClick={() =>
                                    apply({ unmatched: unmatched ? '' : '1' })
                                }
                            >
                                <Link2Off />
                                Without pair
                            </Button>
                            <Button asChild>
                                <Link href={admin.operations.create()}>
                                    <Plus />
                                    Add operation
                                </Link>
                            </Button>
                        </>
                    }
                />

                {Object.keys(byCurrency).length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {Object.entries(byCurrency).map(([currency, rows]) => (
                            <div
                                key={currency}
                                className="rounded-xl border bg-card p-4 shadow-xs"
                            >
                                <div className="mb-2 flex items-center justify-between">
                                    <CurrencyBadge currency={currency} />
                                    <span className="text-xs text-muted-foreground">
                                        {rows.reduce(
                                            (sum, row) => sum + row.operations,
                                            0,
                                        )}{' '}
                                        operations
                                    </span>
                                </div>
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    {rows.map((row) => (
                                        <div
                                            key={row.operation_type}
                                            className="contents"
                                        >
                                            <dt className="text-muted-foreground capitalize">
                                                {row.operation_type} ·{' '}
                                                {row.operations}
                                            </dt>
                                            <dd className="text-right font-medium tabular-nums">
                                                {formatMoney(
                                                    row.amount,
                                                    currency,
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>
                        ))}
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3">
                        <div className="flex flex-col gap-2 md:flex-row">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="ARN, payment ID, MID, e-mail, BIN, last 4 or card masks 535765%2604 492182%1171…"
                                    className="pl-9"
                                />
                            </div>
                            <Input
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(e) =>
                                    apply({ from: e.target.value })
                                }
                                className="md:w-40"
                                aria-label="Report date from"
                            />
                            <Input
                                type="date"
                                value={filters.to ?? ''}
                                onChange={(e) => apply({ to: e.target.value })}
                                className="md:w-40"
                                aria-label="Report date to"
                            />
                        </div>
                        <div className="flex flex-col gap-2 md:flex-row">
                            {select(
                                'provider',
                                'Any provider',
                                providers.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                })),
                                'md:w-48',
                            )}
                            {select('role', 'Any role', roles)}
                            {select('type', 'Any type', types)}
                            {select('region', 'Any region', [
                                { value: 'eu', label: 'EU' },
                                { value: 'non_eu', label: 'Non-EU' },
                            ])}
                            {select(
                                'currency',
                                'Any currency',
                                currencies.map((c) => ({
                                    value: c.value,
                                    label: c.value,
                                })),
                                'md:w-36',
                            )}
                            {hasFilters && (
                                <Button
                                    variant="ghost"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            admin.operations.index.url(),
                                        );
                                    }}
                                >
                                    <X />
                                    Clear
                                </Button>
                            )}
                        </div>
                        <div className="flex flex-col gap-2 md:flex-row">
                            {cascade(
                                'client',
                                'Any client',
                                clients.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                })),
                                ['company', 'mid'],
                            )}
                            {cascade(
                                'company',
                                'Any company',
                                companyOptions.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                })),
                                ['mid'],
                            )}
                            {cascade(
                                'mid',
                                'Any MID',
                                midOptions.map((m) => ({
                                    value: String(m.id),
                                    label: `${m.mid} · ${m.merchant ?? '—'} · ${m.currency}`,
                                })),
                                [],
                            )}
                        </div>
                    </div>

                    {selectedCount > 0 && (
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b bg-primary/5 px-4 py-2.5 text-sm">
                            <span className="font-medium">
                                {allMatching
                                    ? `All ${operations.total} matching the filters selected`
                                    : `${selected.length} selected on this page`}
                            </span>
                            {!allMatching &&
                                hasFilters &&
                                operations.total > pageIds.length &&
                                allOnPage && (
                                    <button
                                        type="button"
                                        className="text-primary hover:underline"
                                        onClick={() => setAllMatching(true)}
                                    >
                                        Select all {operations.total} matching
                                        the filters
                                    </button>
                                )}
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground"
                                onClick={() => {
                                    setSelected([]);
                                    setAllMatching(false);
                                }}
                            >
                                Clear
                            </button>
                            <Button
                                size="sm"
                                variant="destructive"
                                className="ml-auto"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 />
                                Delete…
                            </Button>
                        </div>
                    )}
                    {operations.data.length === 0 ? (
                        <EmptyState
                            icon={ArrowLeftRight}
                            title="No operations"
                            description={
                                hasFilters
                                    ? 'Nothing matches these filters.'
                                    : 'Operations appear here once provider reports are uploaded or fetched by a bot.'
                            }
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">
                                        <Checkbox
                                            aria-label="Select all on this page"
                                            checked={allOnPage}
                                            onCheckedChange={(on) => {
                                                setAllMatching(false);
                                                setSelected(
                                                    on === true ? pageIds : [],
                                                );
                                            }}
                                        />
                                    </TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Provider</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>MID / merchant</TableHead>
                                    <TableHead>Payment ID</TableHead>
                                    <TableHead>Card</TableHead>
                                    <TableHead>Pair</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {operations.data.map((op) => (
                                    <TableRow
                                        key={op.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                admin.operations.show.url(
                                                    op.id,
                                                ),
                                            )
                                        }
                                    >
                                        <TableCell
                                            className="w-10"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <Checkbox
                                                aria-label="Select operation"
                                                checked={
                                                    allMatching ||
                                                    selected.includes(op.id)
                                                }
                                                onCheckedChange={(on) =>
                                                    toggleRow(
                                                        op.id,
                                                        on === true,
                                                    )
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <div>
                                                {formatDate(op.report_date)}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {op.transaction_at
                                                    ? formatDateTime(
                                                          op.transaction_at,
                                                      )
                                                    : '—'}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div>{op.provider?.name}</div>
                                            <RoleLabel role={op.role} />
                                        </TableCell>
                                        <TableCell>
                                            <OperationTypeBadge
                                                type={op.operation_type}
                                                label={op.operation_type_label}
                                            />
                                            {op.collab?.dispute && (
                                                <div className="mt-1 text-xs font-medium text-rose-600">
                                                    Dispute
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-mono text-xs">
                                                {op.mid}
                                            </div>
                                            <div className="max-w-48 truncate text-xs text-muted-foreground">
                                                {op.merchant?.name}
                                            </div>
                                        </TableCell>
                                        <TableCell className="max-w-44 truncate font-mono text-xs">
                                            {op.reference && (
                                                <span className="block font-semibold">
                                                    {op.reference}
                                                </span>
                                            )}
                                            {op.payment_id}
                                        </TableCell>
                                        <TableCell>
                                            <CardLabel
                                                bin={op.card_bin}
                                                last4={op.card_last4}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-36 truncate font-mono text-xs">
                                            {op.sp_id &&
                                            op.matched_operation_id ? (
                                                <Link
                                                    href={admin.operations.show(
                                                        op.matched_operation_id,
                                                    )}
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                    className="hover:underline"
                                                >
                                                    {op.sp_id}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                op.amount,
                                                op.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={operations} noun="operations" />
                </div>
                {deleting && (
                    <BulkDeleteDialog
                        count={selectedCount}
                        mode={allMatching ? 'filtered' : 'selected'}
                        ids={selected}
                        filters={filters}
                        onClose={() => setDeleting(false)}
                    />
                )}
            </PageBody>
        </>
    );
}

OperationsIndex.layout = {
    breadcrumbs: [{ title: 'Operations', href: admin.operations.index() }],
};
