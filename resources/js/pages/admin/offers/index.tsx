import { Head, Link, router } from '@inertiajs/react';
import { FileStack, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import { offerColors } from '@/components/admin/offers';
import type { OfferItem } from '@/components/admin/offers';
import type { Option, Paginated } from '@/types';

type Props = {
    offers: Paginated<OfferItem>;
    statusCounts: Record<string, number>;
    filters: { status?: string; search?: string };
    statuses: Option[];
};

export default function OffersIndex({
    offers,
    statusCounts,
    filters,
    statuses,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);
    const total = Object.values(statusCounts).reduce((a, b) => a + b, 0);
    const apply = (next: Props['filters']) =>
        router.get(
            admin.offers.index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }
        const t = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <>
            <Head title="Commercial offers" />
            <PageBody>
                <PageHeader
                    title="Commercial offers"
                    description="Tariff proposals to prospects. An accepted offer becomes a merchant with the same pricing."
                    actions={
                        <Button asChild>
                            <Link href={admin.offers.create()}>
                                <Plus />
                                New offer
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
                    <div className="border-b p-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Company, number or e-mail…"
                                className="pl-9"
                            />
                        </div>
                    </div>
                    {offers.data.length === 0 ? (
                        <EmptyState
                            icon={FileStack}
                            title="No offers"
                            description="Create an offer to send a prospect your pricing as a PDF."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Offer</TableHead>
                                    <TableHead>Company</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Valid until</TableHead>
                                    <TableHead>Merchant</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {offers.data.map((offer) => (
                                    <TableRow
                                        key={offer.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                admin.offers.edit.url(offer.id),
                                            )
                                        }
                                    >
                                        <TableCell>
                                            <div className="font-mono text-xs font-medium">
                                                {offer.number}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDate(offer.created_at)}
                                                {offer.creator &&
                                                    ` · ${offer.creator}`}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">
                                                {offer.company_name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {[
                                                    offer.contact_email,
                                                    offer.currencies.join(', '),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                name={offer.status_label}
                                                color={
                                                    offerColors[offer.status] ??
                                                    'slate'
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(offer.valid_until)}
                                        </TableCell>
                                        <TableCell>
                                            {offer.merchant ? (
                                                <Link
                                                    href={admin.merchants.show(
                                                        offer.merchant
                                                            .public_id,
                                                    )}
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                    className="hover:underline"
                                                >
                                                    {offer.merchant.name}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={offers} noun="offers" />
                </div>
            </PageBody>
        </>
    );
}

OffersIndex.layout = {
    breadcrumbs: [{ title: 'Commercial offers', href: admin.offers.index() }],
};
