import { Head, Link, router } from '@inertiajs/react';
import { FlaskConical, Plus, Search, Store, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import {
    CurrencyBadge,
    MerchantStatusBadge,
} from '@/components/admin/tone-badge';
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
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type { CompanyRef, Merchant, Option, Paginated } from '@/types';

type Filters = {
    search?: string;
    status?: string;
    currency?: string;
    company?: string;
};

type Props = {
    merchants: Paginated<Merchant>;
    statusCounts: Record<string, number>;
    filters: Filters;
    statuses: Option[];
    currencies: Option[];
    companies: CompanyRef[];
};

const ALL = 'all';

export default function MerchantsIndex({
    merchants,
    statusCounts,
    filters,
    statuses,
    currencies,
    companies,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);
    const total = Object.values(statusCounts).reduce((a, b) => a + b, 0);

    const apply = (next: Filters) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );
        router.get(admin.merchants.index.url(), query, {
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

        const timeout = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(
        filters.search || filters.currency || filters.company,
    );

    return (
        <>
            <Head title="Merchants" />
            <PageBody>
                <PageHeader
                    title="Merchants & MIDs"
                    description="Each merchant has one tariff and reserve policy, and any number of MIDs — one currency per MID."
                    actions={
                        <Button asChild>
                            <Link href={admin.merchants.create()}>
                                <Plus />
                                New merchant
                            </Link>
                        </Button>
                    }
                />

                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    {[{ value: '', label: 'All' }, ...statuses].map(
                        (status) => {
                            const active =
                                (filters.status ?? '') === status.value;
                            const count = status.value
                                ? (statusCounts[status.value] ?? 0)
                                : total;

                            return (
                                <button
                                    key={status.value || 'all'}
                                    type="button"
                                    onClick={() =>
                                        apply({ status: status.value })
                                    }
                                    className={cn(
                                        'flex shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                                        active
                                            ? 'border-primary/30 bg-primary/5 font-medium ring-1 ring-primary/20'
                                            : 'bg-card text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    {status.label}
                                    <span
                                        className={cn(
                                            'rounded-md px-1.5 text-xs tabular-nums',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted',
                                        )}
                                    >
                                        {count}
                                    </span>
                                </button>
                            );
                        },
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3 md:flex-row md:items-center">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by name, public ID or MID…"
                                className="pl-9"
                            />
                        </div>
                        <div className="flex gap-2">
                            <Select
                                value={filters.currency ?? ALL}
                                onValueChange={(value) =>
                                    apply({
                                        currency: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Any currency
                                    </SelectItem>
                                    {currencies.map((currency) => (
                                        <SelectItem
                                            key={currency.value}
                                            value={currency.value}
                                        >
                                            {currency.value} MIDs
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.company ?? ALL}
                                onValueChange={(value) =>
                                    apply({
                                        company: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Any company
                                    </SelectItem>
                                    {companies.map((company) => (
                                        <SelectItem
                                            key={company.id}
                                            value={String(company.id)}
                                        >
                                            {company.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {hasFilters && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Clear filters"
                                    onClick={() => {
                                        setSearch('');
                                        apply({
                                            search: '',
                                            currency: '',
                                            company: '',
                                        });
                                    }}
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                    </div>

                    {merchants.data.length === 0 ? (
                        <EmptyState
                            icon={Store}
                            title={
                                total === 0
                                    ? 'No merchants yet'
                                    : 'Nothing matches these filters'
                            }
                            description={
                                total === 0
                                    ? 'Create a merchant, set its tariff, then add a MID for each currency it processes.'
                                    : 'Try another status or clear the search.'
                            }
                            action={
                                total === 0 && (
                                    <Button asChild>
                                        <Link href={admin.merchants.create()}>
                                            <Plus />
                                            New merchant
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Merchant
                                    </TableHead>
                                    <TableHead className="hidden md:table-cell">
                                        Company
                                    </TableHead>
                                    <TableHead>MIDs</TableHead>
                                    <TableHead className="pr-4 text-right">
                                        Status
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {merchants.data.map((merchant) => {
                                    const byCurrency = (
                                        merchant.mids ?? []
                                    ).reduce<Record<string, number>>(
                                        (acc, mid) => ({
                                            ...acc,
                                            [mid.currency]:
                                                (acc[mid.currency] ?? 0) + 1,
                                        }),
                                        {},
                                    );

                                    return (
                                        <TableRow
                                            key={merchant.id}
                                            className="cursor-pointer"
                                            onClick={() =>
                                                router.visit(
                                                    admin.merchants.show.url(
                                                        merchant.public_id,
                                                    ),
                                                )
                                            }
                                        >
                                            <TableCell className="max-w-[20rem] pl-4">
                                                <Link
                                                    href={admin.merchants.show(
                                                        merchant.public_id,
                                                    )}
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                    className="flex items-center gap-2 truncate font-medium hover:underline"
                                                >
                                                    {merchant.name}
                                                    {merchant.is_test && (
                                                        <FlaskConical className="size-3.5 text-muted-foreground" />
                                                    )}
                                                </Link>
                                                <span className="font-mono text-xs text-muted-foreground">
                                                    {merchant.public_id}
                                                </span>
                                            </TableCell>
                                            <TableCell className="hidden text-muted-foreground md:table-cell">
                                                {merchant.company?.name ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {Object.keys(byCurrency)
                                                    .length === 0 ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        No MIDs
                                                    </span>
                                                ) : (
                                                    <div className="flex gap-1">
                                                        {Object.entries(
                                                            byCurrency,
                                                        ).map(
                                                            ([
                                                                currency,
                                                                count,
                                                            ]) => (
                                                                <CurrencyBadge
                                                                    key={
                                                                        currency
                                                                    }
                                                                    currency={
                                                                        currency
                                                                    }
                                                                    count={
                                                                        count
                                                                    }
                                                                />
                                                            ),
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="pr-4 text-right">
                                                <MerchantStatusBadge
                                                    status={merchant.status}
                                                    label={
                                                        merchant.status_label
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={merchants} noun="merchants" />
                </div>
            </PageBody>
        </>
    );
}

MerchantsIndex.layout = {
    breadcrumbs: [{ title: 'Merchants', href: admin.merchants.index() }],
};
