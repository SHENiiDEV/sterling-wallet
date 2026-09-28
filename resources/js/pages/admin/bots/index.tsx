import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    Image,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { AccountDialog } from '@/components/admin/bots/account-dialog';
import type {
    BotMid,
    BotProvider,
} from '@/components/admin/bots/account-dialog';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EmptyState } from '@/components/admin/empty-state';
import { Field } from '@/components/admin/form';
import { BotRunStatusBadge } from '@/components/admin/operation-badges';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
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
import { formatDate, formatRelative } from '@/lib/format';
import admin from '@/routes/admin';
import type { BotRun, IntegrationAccount, Option, Paginated } from '@/types';

type Props = {
    accounts: IntegrationAccount[];
    runs: Paginated<BotRun>;
    filters: { status?: string; account?: string };
    connectors: Option[];
    statuses: Option[];
    providers: BotProvider[];
    mids: BotMid[];
};

const ALL = 'all';

function yesterday(): string {
    const date = new Date();
    date.setDate(date.getDate() - 1);

    return date.toISOString().slice(0, 10);
}

function RunNowDialog({
    account,
    onClose,
}: {
    account: IntegrationAccount;
    onClose: () => void;
}) {
    const form = useForm({ report_date: yesterday() });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(admin.bots.accounts.run.url(account.id), {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Run {account.name} now</DialogTitle>
                        <DialogDescription>
                            Fetches the report that covers this date (a Friday
                            or weekend day fetches the whole weekend), even if
                            it was fetched before.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Report date"
                        htmlFor="report_date"
                        error={form.errors.report_date}
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
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Spinner /> : <Play />}
                            Queue run
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RunDialog({ run, onClose }: { run: BotRun; onClose: () => void }) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        Run #{run.id} <BotRunStatusBadge status={run.status} />
                    </DialogTitle>
                    <DialogDescription>
                        {run.connector} · {run.account ?? 'deleted account'} ·
                        report {formatDate(run.report_date)} · attempts{' '}
                        {run.attempts}
                        {run.duration_ms !== null &&
                            ` · ${Math.round(run.duration_ms / 1000)} s`}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">MIDs</dt>
                        <dd className="font-mono text-xs">
                            {run.mids.join(', ') || '—'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Files / rows</dt>
                        <dd className="text-xs">
                            {run.files.join(', ') || '—'}
                            {run.rows_count !== null &&
                                ` · ${run.rows_count} rows`}
                        </dd>
                    </div>
                </dl>

                {run.error && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm whitespace-pre-wrap">
                        {run.error}
                    </div>
                )}

                {run.has_screenshot && (
                    <a
                        href={admin.bots.runs.screenshot.url(run.id)}
                        target="_blank"
                        rel="noreferrer"
                        className="block overflow-hidden rounded-lg border"
                    >
                        <img
                            src={admin.bots.runs.screenshot.url(run.id)}
                            alt="Screenshot at the moment of the error"
                            className="w-full"
                        />
                    </a>
                )}

                {run.log && (
                    <pre className="max-h-72 overflow-auto rounded-lg bg-muted p-3 text-xs">
                        {run.log}
                    </pre>
                )}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Close
                        </Button>
                    </DialogClose>
                    {!['queued', 'running'].includes(run.status) && (
                        <Button
                            onClick={() =>
                                router.post(
                                    admin.bots.runs.retry.url(run.id),
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: onClose,
                                    },
                                )
                            }
                        >
                            <RotateCcw />
                            Retry
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function BotsIndex({
    accounts,
    runs,
    filters,
    connectors,
    statuses,
    providers,
    mids,
}: Props) {
    const [editing, setEditing] = useState<IntegrationAccount | 'new' | null>(
        null,
    );
    const [running, setRunning] = useState<IntegrationAccount | null>(null);
    const [deleting, setDeleting] = useState<IntegrationAccount | null>(null);
    const [viewing, setViewing] = useState<BotRun | null>(null);
    const connectorLabel = (code: string) =>
        connectors.find((c) => c.value === code)?.label ?? code;

    const filter = (next: { status?: string; account?: string }) =>
        router.get(
            admin.bots.index.url(),
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Bots" />
            <PageBody>
                <PageHeader
                    title="Bots"
                    description="Bots fetch provider reports every 30 minutes for every MID and date still missing a file, then hand them to the report pipeline."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Add account
                        </Button>
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    {accounts.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="No bot accounts yet"
                            description="Add the portal login a bot should use, e.g. the Corefy dashboard or the webmail that receives Cardaq reports."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Account</TableHead>
                                    <TableHead>Bot</TableHead>
                                    <TableHead>Covers</TableHead>
                                    <TableHead>Last run</TableHead>
                                    <TableHead className="w-0" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accounts.map((account) => (
                                    <TableRow key={account.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {account.name}
                                                {!account.is_active && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        (paused)
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {account.provider.name}
                                                {account.has_totp && ' · 2FA'}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {connectorLabel(account.connector)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {account.mid_ids.length
                                                ? `${account.mid_ids.length} MID(s)`
                                                : 'All provider MIDs'}
                                        </TableCell>
                                        <TableCell>
                                            {account.last_run ? (
                                                <button
                                                    type="button"
                                                    className="flex items-center gap-2 text-left"
                                                    onClick={() =>
                                                        setViewing(
                                                            account.last_run,
                                                        )
                                                    }
                                                >
                                                    <BotRunStatusBadge
                                                        status={
                                                            account.last_run
                                                                .status
                                                        }
                                                    />
                                                    <span className="text-xs text-muted-foreground">
                                                        {formatRelative(
                                                            account.last_run
                                                                .created_at,
                                                        )}
                                                    </span>
                                                </button>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    Never
                                                </span>
                                            )}
                                            {account.failures_in_row >= 3 && (
                                                <div className="mt-1 flex items-center gap-1 text-xs text-destructive">
                                                    <AlertTriangle className="size-3.5" />
                                                    {account.failures_in_row}{' '}
                                                    failures in a row
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setRunning(account)
                                                    }
                                                >
                                                    <Play />
                                                    Run now
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label="Edit"
                                                    onClick={() =>
                                                        setEditing(account)
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label="Delete"
                                                    onClick={() =>
                                                        setDeleting(account)
                                                    }
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
                </div>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3 md:flex-row md:items-center md:justify-between">
                        <h2 className="text-sm font-semibold">Recent runs</h2>
                        <div className="flex gap-2">
                            <Select
                                value={filters.account ?? ALL}
                                onValueChange={(value) =>
                                    filter({
                                        account: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-52">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All accounts
                                    </SelectItem>
                                    {accounts.map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={String(account.id)}
                                        >
                                            {account.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.status ?? ALL}
                                onValueChange={(value) =>
                                    filter({
                                        status: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Any status
                                    </SelectItem>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={status.value}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {runs.data.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="No runs yet"
                            description="Runs appear once the scheduler (or Run now) queues work."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>#</TableHead>
                                    <TableHead>Report</TableHead>
                                    <TableHead>Account</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Result</TableHead>
                                    <TableHead>Started</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.map((run) => (
                                    <TableRow
                                        key={run.id}
                                        className="cursor-pointer"
                                        onClick={() => setViewing(run)}
                                    >
                                        <TableCell className="text-muted-foreground tabular-nums">
                                            {run.id}
                                        </TableCell>
                                        <TableCell>
                                            <div>
                                                {formatDate(run.report_date)}
                                            </div>
                                            <div className="max-w-48 truncate font-mono text-xs text-muted-foreground">
                                                {run.mids.join(', ')}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {run.account ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <BotRunStatusBadge
                                                status={run.status}
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-72 text-xs">
                                            {run.error ? (
                                                <span className="line-clamp-2 text-destructive">
                                                    {run.has_screenshot && (
                                                        <Image className="mr-1 inline size-3.5" />
                                                    )}
                                                    {run.error}
                                                </span>
                                            ) : run.rows_count !== null ? (
                                                `${run.rows_count} rows`
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                            {formatRelative(run.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={runs} noun="runs" />
                </div>
            </PageBody>

            {editing && (
                <AccountDialog
                    account={editing === 'new' ? null : editing}
                    providers={providers}
                    connectors={connectors}
                    mids={mids}
                    onClose={() => setEditing(null)}
                />
            )}
            {running && (
                <RunNowDialog
                    account={running}
                    onClose={() => setRunning(null)}
                />
            )}
            {viewing && (
                <RunDialog run={viewing} onClose={() => setViewing(null)} />
            )}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? 'account'}?`}
                description="The run history stays; the stored credentials are removed."
                onConfirm={() =>
                    deleting &&
                    router.delete(
                        admin.bots.accounts.destroy.url(deleting.id),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        },
                    )
                }
            />
        </>
    );
}

BotsIndex.layout = {
    breadcrumbs: [{ title: 'Bots', href: admin.bots.index() }],
};
