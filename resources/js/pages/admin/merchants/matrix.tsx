import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Grid3x3, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { acquirerColor } from '@/components/admin/merchants/acquirers-section';
import { ViewSwitch } from '@/components/admin/merchants/view-switch';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Cell = {
    status: string;
    status_label: string;
    since: string | null;
    stale_days: number | null;
    limit: string | null;
    limit_currency: string;
    client_id: number | null;
    client: string | null;
    integration: string;
    integration_label: string;
    integration_auto: boolean;
};

type Row = {
    public_id: string;
    name: string;
    company: string | null;
    website: string | null;
    cells: Record<string, Cell>;
};

type Props = {
    banks: { id: number; name: string; is_active: boolean }[];
    rows: Row[];
    clients: { id: number; name: string }[];
    withoutClient: number;
};

const ALL = 'all';

function CellView({ cell, href }: { cell: Cell; href: string }) {
    return (
        <Link
            href={href}
            className="flex min-w-36 flex-col gap-1 rounded-md p-1.5 transition hover:bg-accent"
        >
            <span className="flex items-center gap-1.5">
                <StatusBadge
                    name={cell.status_label}
                    color={acquirerColor(cell.status)}
                />
                {cell.stale_days !== null && (
                    <span
                        title={`In this status for ${cell.stale_days} days`}
                        className="inline-flex items-center gap-0.5 text-xs font-medium text-amber-600"
                    >
                        <AlertTriangle className="size-3.5" />
                        {cell.stale_days}d
                    </span>
                )}
            </span>
            <span
                className={cn(
                    'text-xs',
                    cell.client
                        ? 'text-foreground'
                        : 'font-medium text-amber-600',
                )}
            >
                {cell.client ?? 'No client'}
            </span>
            <span className="text-xs text-muted-foreground tabular-nums">
                {cell.limit
                    ? formatMoney(cell.limit, cell.limit_currency, 0)
                    : '—'}
                {' · '}
                {cell.integration_label}
                {cell.integration_auto && ' (auto)'}
            </span>
        </Link>
    );
}

export default function MerchantMatrix({
    banks,
    rows,
    clients,
    withoutClient,
}: Props) {
    const [client, setClient] = useState(ALL);
    const [search, setSearch] = useState('');
    const [onlyPending, setOnlyPending] = useState(false);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();

        return rows.filter((row) => {
            const cells = Object.values(row.cells);
            if (
                q &&
                !`${row.name} ${row.company ?? ''} ${row.website ?? ''}`
                    .toLowerCase()
                    .includes(q)
            ) {
                return false;
            }
            if (client === 'none' && !cells.some((c) => c.client_id === null)) {
                return false;
            }
            if (
                client !== ALL &&
                client !== 'none' &&
                !cells.some((c) => String(c.client_id) === client)
            ) {
                return false;
            }
            if (
                onlyPending &&
                !cells.some(
                    (c) => c.status !== 'active_mids' && c.status !== 'closed',
                )
            ) {
                return false;
            }

            return true;
        });
    }, [rows, client, search, onlyPending]);

    return (
        <>
            <Head title="Matrix" />
            <PageBody>
                <PageHeader
                    title="Merchants"
                    description="Every company and site against every bank: where it stands, for which client, and whether the gateway is live."
                    actions={
                        <>
                            <ViewSwitch view="matrix" />
                            <Button asChild>
                                <Link href={admin.merchants.create()}>
                                    <Plus />
                                    New merchant
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Company or site…"
                        className="w-56"
                    />
                    <Select value={client} onValueChange={setClient}>
                        <SelectTrigger className="w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All clients</SelectItem>
                            <SelectItem value="none">
                                No client ({withoutClient})
                            </SelectItem>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        variant={onlyPending ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => setOnlyPending((v) => !v)}
                    >
                        Not live yet
                    </Button>
                </div>

                {shown.length === 0 ? (
                    <EmptyState
                        icon={Grid3x3}
                        title="Nothing matches"
                        description="Change the filters, or add banks on a merchant's page."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card shadow-xs">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-muted-foreground">
                                    <th className="sticky left-0 z-10 bg-card px-4 py-2.5 font-medium">
                                        Company · site
                                    </th>
                                    {banks.map((bank) => (
                                        <th
                                            key={bank.id}
                                            className="px-2 py-2.5 font-medium"
                                        >
                                            <Link
                                                href={admin.merchants.banks.url(
                                                    {
                                                        query: {
                                                            bank: bank.id,
                                                        },
                                                    },
                                                )}
                                                className="hover:underline"
                                            >
                                                {bank.name}
                                            </Link>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((row) => (
                                    <tr
                                        key={row.public_id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="sticky left-0 z-10 bg-card px-4 py-2 align-top">
                                            <Link
                                                href={admin.merchants.show(
                                                    row.public_id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {row.company ?? row.name}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {row.website ?? row.name}
                                            </div>
                                        </td>
                                        {banks.map((bank) => {
                                            const cell = row.cells[bank.id];

                                            return (
                                                <td
                                                    key={bank.id}
                                                    className="px-1 py-1 align-top"
                                                >
                                                    {cell ? (
                                                        <CellView
                                                            cell={cell}
                                                            href={admin.merchants.show.url(
                                                                row.public_id,
                                                                {
                                                                    query: {
                                                                        tab: 'banks',
                                                                    },
                                                                },
                                                            )}
                                                        />
                                                    ) : (
                                                        <span className="block p-1.5 text-muted-foreground/40">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </PageBody>
        </>
    );
}
