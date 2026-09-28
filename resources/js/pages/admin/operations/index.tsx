import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRight, Link2Off, Search, X } from 'lucide-react';
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
};

type Props = {
    operations: Paginated<Operation>;
    totals: OperationTotal[];
    filters: Filters;
    providers: ProviderRef[];
    roles: Option[];
    types: Option[];
    currencies: Option[];
};

const ALL = 'all';

export default function OperationsIndex({
    operations,
    totals,
    filters,
    providers,
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
                        <Button
                            variant={unmatched ? 'default' : 'outline'}
                            onClick={() =>
                                apply({ unmatched: unmatched ? '' : '1' })
                            }
                        >
                            <Link2Off />
                            Without pair
                        </Button>
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
                                    placeholder="ARN, payment ID, pair ID, MID, e-mail, BIN or last 4…"
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
                    </div>

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
            </PageBody>
        </>
    );
}

OperationsIndex.layout = {
    breadcrumbs: [{ title: 'Operations', href: admin.operations.index() }],
};
