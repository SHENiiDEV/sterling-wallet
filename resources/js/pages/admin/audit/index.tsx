import { Head, router } from '@inertiajs/react';
import { ScrollText, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
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
import { formatDateTime } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type Log = {
    id: number;
    at: string | null;
    user: string | null;
    action: string;
    subject: string | null;
    ip: string | null;
    meta: Record<string, unknown> | null;
};

type Filters = {
    user?: string;
    action?: string;
    search?: string;
    from?: string;
    to?: string;
};

type Props = {
    logs: Paginated<Log>;
    filters: Filters;
    users: { id: number; name: string }[];
    areas: string[];
};

const ALL = 'all';

export default function AuditIndex({ logs, filters, users, areas }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    const apply = (next: Filters) =>
        router.get(
            admin.audit.index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveScroll: true, preserveState: true, replace: true },
        );

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

    return (
        <>
            <Head title="Audit log" />
            <PageBody>
                <PageHeader
                    title="Audit log"
                    description="Who did what in the console: downloads and exports, settlements, access changes."
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3 md:flex-row">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search action, IP, details or ID…"
                            className="md:flex-1"
                        />
                        <Select
                            value={filters.user ?? ALL}
                            onValueChange={(v) =>
                                apply({ user: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Anyone</SelectItem>
                                {users.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.action ?? ALL}
                            onValueChange={(v) =>
                                apply({ action: v === ALL ? '' : v })
                            }
                        >
                            <SelectTrigger className="w-full md:w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>Any area</SelectItem>
                                {areas.map((a) => (
                                    <SelectItem key={a} value={a}>
                                        {a}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            type="date"
                            value={filters.from ?? ''}
                            onChange={(e) => apply({ from: e.target.value })}
                            className="md:w-40"
                            aria-label="From"
                        />
                        <Input
                            type="date"
                            value={filters.to ?? ''}
                            onChange={(e) => apply({ to: e.target.value })}
                            className="md:w-40"
                            aria-label="To"
                        />
                        {hasFilters && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSearch('');
                                    router.get(admin.audit.index.url());
                                }}
                            >
                                <X />
                                Clear
                            </Button>
                        )}
                    </div>

                    {logs.data.length === 0 ? (
                        <EmptyState
                            icon={ScrollText}
                            title="No entries"
                            description="Nothing matches these filters."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Time</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Action</TableHead>
                                    <TableHead>Object</TableHead>
                                    <TableHead>Details</TableHead>
                                    <TableHead>IP</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.data.map((log) => (
                                    <TableRow key={log.id}>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {log.at
                                                ? formatDateTime(log.at)
                                                : '—'}
                                        </TableCell>
                                        <TableCell>{log.user ?? '—'}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {log.action}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {log.subject ?? '—'}
                                        </TableCell>
                                        <TableCell className="max-w-sm">
                                            {log.meta ? (
                                                <details>
                                                    <summary className="cursor-pointer text-xs text-primary">
                                                        {Object.keys(
                                                            log.meta,
                                                        ).join(', ')}
                                                    </summary>
                                                    <pre className="mt-1 max-h-40 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap">
                                                        {JSON.stringify(
                                                            log.meta,
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </details>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs text-muted-foreground">
                                            {log.ip ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={logs} noun="entries" />
                </div>
            </PageBody>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [{ title: 'Audit log', href: admin.audit.index() }],
};
