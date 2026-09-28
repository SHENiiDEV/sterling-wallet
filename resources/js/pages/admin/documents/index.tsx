import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    ChevronDown,
    FolderKanban,
    Paperclip,
    Plus,
    Search,
    Settings2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import {
    DocumentFields,
    type DocumentFormData,
} from '@/components/admin/documents/document-fields';
import { FileDrop } from '@/components/admin/documents/file-drop';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Pagination } from '@/components/admin/pagination';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type {
    DocumentItem,
    DocumentStatus,
    Option,
    Paginated,
    StaffMember,
} from '@/types';

type Filters = {
    search?: string;
    status?: string;
    type?: string;
    owner?: string;
    overdue?: string;
};

type Props = {
    documents: Paginated<DocumentItem>;
    statuses: DocumentStatus[];
    totals: { all: number; overdue: number };
    filters: Filters;
    types: Option[];
    staff: StaffMember[];
};

const ALL = 'all';

export default function DocumentsIndex({
    documents,
    statuses,
    totals,
    filters,
    types,
    staff,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);

    const applyFilters = (next: Filters) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        );

        router.get(admin.documents.index.url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => applyFilters({ search }), 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const activeStatus = filters.status ? Number(filters.status) : null;
    const overdueOnly = Boolean(Number(filters.overdue ?? 0));
    const hasFilters = Boolean(filters.search || filters.type || filters.owner);

    const changeStatus = (document: DocumentItem, status: DocumentStatus) => {
        router.put(
            admin.documents.status.url(document.id),
            { document_status_id: status.id },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title="Document Center" />
            <PageBody>
                <PageHeader
                    title="Document Center"
                    description="Contracts, KYB packs and agreements — who has them and where they stand."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={admin.documentStatuses.index()}>
                                    <Settings2 />
                                    Statuses
                                </Link>
                            </Button>
                            <Button onClick={() => setCreating(true)}>
                                <Plus />
                                New document
                            </Button>
                        </>
                    }
                />

                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    <PipelineChip
                        label="All"
                        count={totals.all}
                        active={!activeStatus && !overdueOnly}
                        onClick={() =>
                            applyFilters({ status: '', overdue: '' })
                        }
                    />
                    {statuses.map((status) => (
                        <PipelineChip
                            key={status.id}
                            label={status.name}
                            count={status.documents_count ?? 0}
                            dot={<StatusDot color={status.color} />}
                            active={activeStatus === status.id}
                            onClick={() =>
                                applyFilters({
                                    status: String(status.id),
                                    overdue: '',
                                })
                            }
                        />
                    ))}
                    <PipelineChip
                        label="Overdue"
                        count={totals.overdue}
                        dot={
                            <AlertTriangle className="size-3.5 text-destructive-foreground" />
                        }
                        active={overdueOnly}
                        onClick={() =>
                            applyFilters({ status: '', overdue: '1' })
                        }
                    />
                </div>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-2 border-b p-3 md:flex-row md:items-center">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search by title or counterparty…"
                                className="pl-9"
                            />
                        </div>
                        <div className="flex gap-2">
                            <Select
                                value={filters.type ?? ALL}
                                onValueChange={(value) =>
                                    applyFilters({
                                        type: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All types
                                    </SelectItem>
                                    {types.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.owner ?? ALL}
                                onValueChange={(value) =>
                                    applyFilters({
                                        owner: value === ALL ? '' : value,
                                    })
                                }
                            >
                                <SelectTrigger className="w-full md:w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        Any owner
                                    </SelectItem>
                                    {staff.map((member) => (
                                        <SelectItem
                                            key={member.id}
                                            value={String(member.id)}
                                        >
                                            {member.name}
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
                                        applyFilters({
                                            search: '',
                                            type: '',
                                            owner: '',
                                        });
                                    }}
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                    </div>

                    {documents.data.length === 0 ? (
                        <EmptyState
                            icon={FolderKanban}
                            title={
                                totals.all === 0
                                    ? 'No documents yet'
                                    : 'Nothing matches these filters'
                            }
                            description={
                                totals.all === 0
                                    ? 'Add the first contract or KYB pack and track it from draft to signed.'
                                    : 'Try another status or clear the search.'
                            }
                            action={
                                totals.all === 0 && (
                                    <Button onClick={() => setCreating(true)}>
                                        <Plus />
                                        New document
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Document
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden md:table-cell">
                                        Owner
                                    </TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        Due
                                    </TableHead>
                                    <TableHead className="hidden text-right lg:table-cell">
                                        Files
                                    </TableHead>
                                    <TableHead className="hidden pr-4 text-right lg:table-cell">
                                        Updated
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.map((document) => (
                                    <TableRow
                                        key={document.id}
                                        className="cursor-pointer"
                                        onClick={() =>
                                            router.visit(
                                                admin.documents.show.url(
                                                    document.id,
                                                ),
                                            )
                                        }
                                    >
                                        <TableCell className="max-w-[22rem] pl-4">
                                            <Link
                                                href={admin.documents.show(
                                                    document.id,
                                                )}
                                                className="block truncate font-medium hover:underline"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                {document.title}
                                            </Link>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {document.type_label}
                                                {document.counterparty &&
                                                    ` · ${document.counterparty}`}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <DropdownMenu>
                                                <DropdownMenuTrigger className="group inline-flex items-center gap-1 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                                    <StatusBadge
                                                        name={
                                                            document.status.name
                                                        }
                                                        color={
                                                            document.status
                                                                .color
                                                        }
                                                    />
                                                    <ChevronDown className="size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="start">
                                                    <DropdownMenuLabel>
                                                        Move to
                                                    </DropdownMenuLabel>
                                                    <DropdownMenuSeparator />
                                                    {statuses.map((status) => (
                                                        <DropdownMenuItem
                                                            key={status.id}
                                                            disabled={
                                                                status.id ===
                                                                document.status
                                                                    .id
                                                            }
                                                            onSelect={() =>
                                                                changeStatus(
                                                                    document,
                                                                    status,
                                                                )
                                                            }
                                                        >
                                                            <StatusDot
                                                                color={
                                                                    status.color
                                                                }
                                                            />
                                                            {status.name}
                                                        </DropdownMenuItem>
                                                    ))}
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground md:table-cell">
                                            {document.owner?.name ?? '—'}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <span
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 tabular-nums',
                                                    document.is_overdue
                                                        ? 'font-medium text-destructive-foreground'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {document.is_overdue && (
                                                    <CalendarClock className="size-3.5" />
                                                )}
                                                {formatDate(document.due_date)}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden text-right text-muted-foreground tabular-nums lg:table-cell">
                                            {document.files_count ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <Paperclip className="size-3.5" />
                                                    {document.files_count}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden pr-4 text-right text-muted-foreground lg:table-cell">
                                            {formatRelative(
                                                document.updated_at,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={documents} noun="documents" />
                </div>
            </PageBody>

            <CreateDocumentDialog
                open={creating}
                onOpenChange={setCreating}
                statuses={statuses}
                types={types}
                staff={staff}
            />
        </>
    );
}

function PipelineChip({
    label,
    count,
    active,
    dot,
    onClick,
}: {
    label: string;
    count: number;
    active: boolean;
    dot?: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex shrink-0 items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors',
                active
                    ? 'border-primary/30 bg-primary/5 font-medium text-foreground ring-1 ring-primary/20'
                    : 'bg-card text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
        >
            {dot}
            <span className="whitespace-nowrap">{label}</span>
            <span
                className={cn(
                    'rounded-md px-1.5 text-xs tabular-nums',
                    active ? 'bg-primary text-primary-foreground' : 'bg-muted',
                )}
            >
                {count}
            </span>
        </button>
    );
}

function CreateDocumentDialog({
    open,
    onOpenChange,
    statuses,
    types,
    staff,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    statuses: DocumentStatus[];
    types: Option[];
    staff: StaffMember[];
}) {
    const defaultStatus =
        statuses.find((status) => status.is_default) ?? statuses[0];

    const form = useForm<DocumentFormData & { files: File[] }>({
        title: '',
        type: 'contract',
        counterparty: '',
        owner_id: '',
        due_date: '',
        notes: '',
        document_status_id: defaultStatus ? String(defaultStatus.id) : '',
        files: [],
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(admin.documents.store.url(), {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>New document</DialogTitle>
                        <DialogDescription>
                            It starts in the chosen status; every change is
                            recorded in its history.
                        </DialogDescription>
                    </DialogHeader>

                    <DocumentFields
                        data={form.data}
                        setData={form.setData}
                        errors={form.errors}
                        types={types}
                        staff={staff}
                        statuses={statuses}
                    />

                    <FileDrop
                        files={form.data.files}
                        onChange={(files) => form.setData('files', files)}
                    />
                    {Object.entries(form.errors)
                        .filter(([key]) => key.startsWith('files'))
                        .map(([key, message]) => (
                            <p
                                key={key}
                                className="-mt-3 text-sm text-red-600 dark:text-red-400"
                            >
                                {message}
                            </p>
                        ))}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Create document
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

DocumentsIndex.layout = {
    breadcrumbs: [{ title: 'Document Center', href: admin.documents.index() }],
};
