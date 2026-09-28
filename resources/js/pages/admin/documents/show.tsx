import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    ArrowRight,
    CalendarClock,
    Check,
    Download,
    FilePlus2,
    FileText,
    MessageSquare,
    Paperclip,
    Pencil,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import {
    DocumentFields,
    type DocumentFormData,
} from '@/components/admin/documents/document-fields';
import { FileDrop } from '@/components/admin/documents/file-drop';
import { PageBody } from '@/components/admin/page-header';
import { StatusBadge, StatusDot } from '@/components/admin/status-badge';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatBytes,
    formatDate,
    formatDateTime,
    formatRelative,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type {
    CompanyRef,
    DocumentItem,
    DocumentStatus,
    MerchantRef,
    Option,
    StaffMember,
} from '@/types';

type FileItem = {
    id: number;
    name: string;
    mime_type: string | null;
    size: number;
    uploaded_by: string | null;
    created_at: string;
};

type Activity = {
    id: number;
    type:
        | 'created'
        | 'updated'
        | 'status_changed'
        | 'file_uploaded'
        | 'file_removed'
        | 'comment';
    user: string | null;
    comment: string | null;
    meta: { name?: string; fields?: string[] } | null;
    from_status: DocumentStatus | null;
    to_status: DocumentStatus | null;
    created_at: string;
};

type Props = {
    document: DocumentItem;
    files: FileItem[];
    activities: Activity[];
    statuses: DocumentStatus[];
    types: Option[];
    staff: StaffMember[];
    companies: CompanyRef[];
    merchants: MerchantRef[];
};

export default function DocumentShow({
    document,
    files,
    activities,
    statuses,
    types,
    staff,
    companies,
    merchants,
}: Props) {
    const [editing, setEditing] = useState(false);

    return (
        <>
            <Head title={document.title} />
            <PageBody>
                <div className="flex flex-col gap-4">
                    <Link
                        href={admin.documents.index()}
                        className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Document Center
                    </Link>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0 space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    name={document.status.name}
                                    color={document.status.color}
                                />
                                <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                    {document.type_label}
                                </span>
                                {document.is_overdue && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2.5 py-0.5 text-xs font-medium text-destructive-foreground">
                                        <CalendarClock className="size-3.5" />
                                        Overdue
                                    </span>
                                )}
                            </div>
                            <h1 className="text-2xl font-semibold tracking-tight break-words">
                                {document.title}
                            </h1>
                            {document.counterparty && (
                                <p className="text-sm text-muted-foreground">
                                    {document.counterparty}
                                </p>
                            )}
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil />
                                Edit
                            </Button>
                            <ArchiveButton document={document} />
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid content-start gap-6">
                        <DetailsCard document={document} />
                        <FilesCard document={document} files={files} />
                        <TimelineCard
                            document={document}
                            activities={activities}
                        />
                    </div>
                    <div className="grid content-start gap-6">
                        <StatusCard document={document} statuses={statuses} />
                    </div>
                </div>
            </PageBody>

            <EditDialog
                open={editing}
                onOpenChange={setEditing}
                document={document}
                types={types}
                staff={staff}
                companies={companies}
                merchants={merchants}
            />
        </>
    );
}

function Card({
    title,
    icon: Icon,
    action,
    children,
    className,
}: {
    title: string;
    icon: React.ComponentType<{ className?: string }>;
    action?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn('rounded-xl border bg-card shadow-xs', className)}
        >
            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className="size-4 text-muted-foreground" />
                    {title}
                </h2>
                {action}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

function DetailsCard({ document }: { document: DocumentItem }) {
    const rows: [string, React.ReactNode][] = [
        ['Counterparty', document.counterparty ?? '—'],
        ['Company', document.company?.name ?? '—'],
        [
            'Merchant',
            document.merchant ? (
                <Link
                    href={admin.merchants.show(document.merchant.public_id)}
                    className="font-medium text-brand hover:underline"
                >
                    {document.merchant.name}
                </Link>
            ) : (
                '—'
            ),
        ],
        ['Owner', document.owner?.name ?? 'Unassigned'],
        [
            'Due date',
            <span
                className={cn(
                    document.is_overdue &&
                        'font-medium text-destructive-foreground',
                )}
            >
                {formatDate(document.due_date)}
            </span>,
        ],
        ['In status since', formatDateTime(document.status_changed_at)],
        ['Created', formatDateTime(document.created_at)],
    ];

    return (
        <Card title="Details" icon={FileText}>
            <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                {rows.map(([label, value]) => (
                    <div key={label} className="grid gap-1">
                        <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {label}
                        </dt>
                        <dd className="text-sm">{value}</dd>
                    </div>
                ))}
            </dl>
            {document.notes && (
                <div className="mt-5 rounded-lg bg-muted/50 p-4 text-sm leading-relaxed whitespace-pre-line">
                    {document.notes}
                </div>
            )}
        </Card>
    );
}

function StatusCard({
    document,
    statuses,
}: {
    document: DocumentItem;
    statuses: DocumentStatus[];
}) {
    const form = useForm({
        document_status_id: String(document.status.id),
        comment: '',
    });
    const target = statuses.find(
        (status) => String(status.id) === form.data.document_status_id,
    );
    const changed = target && target.id !== document.status.id;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(admin.documents.status.url(document.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('comment'),
        });
    };

    return (
        <Card title="Status" icon={ArrowRight}>
            <form onSubmit={submit} className="grid gap-4">
                <ol className="grid gap-1">
                    {statuses.map((status) => {
                        const current = status.id === document.status.id;
                        const selected =
                            String(status.id) === form.data.document_status_id;

                        return (
                            <li key={status.id}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'document_status_id',
                                            String(status.id),
                                        )
                                    }
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                                        selected
                                            ? 'border-primary/40 bg-primary/5 ring-1 ring-primary/20'
                                            : 'border-transparent hover:bg-muted',
                                    )}
                                >
                                    <StatusDot color={status.color} />
                                    <span
                                        className={cn(
                                            'flex-1',
                                            selected && 'font-medium',
                                        )}
                                    >
                                        {status.name}
                                    </span>
                                    {current && (
                                        <span className="text-xs text-muted-foreground">
                                            current
                                        </span>
                                    )}
                                    {selected && !current && (
                                        <Check className="size-4 text-primary" />
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ol>

                {changed && (
                    <>
                        <Textarea
                            value={form.data.comment}
                            onChange={(e) =>
                                form.setData('comment', e.target.value)
                            }
                            placeholder="Comment (optional) — e.g. sent for signature via DocuSign"
                            rows={2}
                        />
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Move to {target.name}
                        </Button>
                    </>
                )}
            </form>
        </Card>
    );
}

function FilesCard({
    document,
    files,
}: {
    document: DocumentItem;
    files: FileItem[];
}) {
    const form = useForm<{ files: File[] }>({ files: [] });
    const [uploading, setUploading] = useState(false);

    const upload = () => {
        form.post(admin.documents.files.store.url(document.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setUploading(false);
            },
        });
    };

    return (
        <Card
            title={`Files${files.length ? ` · ${files.length}` : ''}`}
            icon={Paperclip}
            action={
                !uploading && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setUploading(true)}
                    >
                        <Upload />
                        Upload
                    </Button>
                )
            }
        >
            {uploading && (
                <div className="mb-5 grid gap-3">
                    <FileDrop
                        compact
                        files={form.data.files}
                        onChange={(list) => form.setData('files', list)}
                    />
                    {Object.entries(form.errors).map(([key, message]) => (
                        <p
                            key={key}
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {message}
                        </p>
                    ))}
                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                form.reset();
                                setUploading(false);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                form.processing || form.data.files.length === 0
                            }
                            onClick={upload}
                        >
                            {form.processing && <Spinner />}
                            Upload {form.data.files.length || ''}
                        </Button>
                    </div>
                </div>
            )}

            {files.length === 0 && !uploading ? (
                <button
                    type="button"
                    onClick={() => setUploading(true)}
                    className="flex w-full flex-col items-center gap-2 rounded-lg border border-dashed py-8 text-sm text-muted-foreground hover:bg-muted/40"
                >
                    <FilePlus2 className="size-5" />
                    No files yet — attach the latest version
                </button>
            ) : (
                <ul className="divide-y">
                    {files.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-[10px] font-semibold text-brand uppercase">
                                {file.name.split('.').pop()?.slice(0, 4)}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {file.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatBytes(file.size)} ·{' '}
                                    {file.uploaded_by ?? 'System'} ·{' '}
                                    {formatRelative(file.created_at)}
                                </p>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                asChild
                                aria-label={`Download ${file.name}`}
                            >
                                <a
                                    href={admin.documents.files.show.url({
                                        document: document.id,
                                        file: file.id,
                                    })}
                                >
                                    <Download />
                                </a>
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label={`Remove ${file.name}`}
                                onClick={() => {
                                    if (confirm(`Remove ${file.name}?`)) {
                                        router.delete(
                                            admin.documents.files.destroy.url({
                                                document: document.id,
                                                file: file.id,
                                            }),
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function TimelineCard({
    document,
    activities,
}: {
    document: DocumentItem;
    activities: Activity[];
}) {
    const form = useForm({ comment: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(admin.documents.comments.store.url(document.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <Card title="History" icon={MessageSquare}>
            <form onSubmit={submit} className="mb-6 grid gap-2">
                <Textarea
                    value={form.data.comment}
                    onChange={(e) => form.setData('comment', e.target.value)}
                    placeholder="Leave a note for the team…"
                    rows={2}
                />
                <div className="flex justify-end">
                    <Button
                        size="sm"
                        type="submit"
                        disabled={
                            form.processing || form.data.comment.trim() === ''
                        }
                    >
                        Add note
                    </Button>
                </div>
            </form>

            <ol className="relative grid gap-5 before:absolute before:top-2 before:bottom-2 before:left-[5px] before:w-px before:bg-border">
                {activities.map((activity) => (
                    <li key={activity.id} className="relative grid gap-1 pl-6">
                        <span
                            className={cn(
                                'absolute top-1.5 left-0 size-[11px] rounded-full border-2 border-card',
                                activity.type === 'status_changed' &&
                                    activity.to_status
                                    ? ''
                                    : 'bg-muted-foreground/40',
                            )}
                        >
                            {activity.type === 'status_changed' &&
                                activity.to_status && (
                                    <StatusDot
                                        color={activity.to_status.color}
                                        className="size-full"
                                    />
                                )}
                        </span>
                        <p className="text-sm">
                            <span className="font-medium">
                                {activity.user ?? 'System'}
                            </span>{' '}
                            <ActivityText activity={activity} />
                        </p>
                        {activity.comment && (
                            <p className="rounded-lg bg-muted/50 px-3 py-2 text-sm whitespace-pre-line">
                                {activity.comment}
                            </p>
                        )}
                        <time
                            className="text-xs text-muted-foreground"
                            title={formatDateTime(activity.created_at)}
                        >
                            {formatRelative(activity.created_at)}
                        </time>
                    </li>
                ))}
            </ol>
        </Card>
    );
}

function ActivityText({ activity }: { activity: Activity }) {
    switch (activity.type) {
        case 'created':
            return (
                <span className="text-muted-foreground">
                    created the document
                </span>
            );
        case 'updated':
            return (
                <span className="text-muted-foreground">
                    updated{' '}
                    {activity.meta?.fields?.join(', ').replaceAll('_', ' ') ??
                        'details'}
                </span>
            );
        case 'status_changed':
            return (
                <span className="inline-flex flex-wrap items-center gap-1.5 text-muted-foreground">
                    <span>moved it</span>
                    {activity.from_status && (
                        <>
                            <span>from</span>
                            <StatusBadge
                                name={activity.from_status.name}
                                color={activity.from_status.color}
                            />
                        </>
                    )}
                    {activity.to_status && (
                        <>
                            <span>to</span>
                            <StatusBadge
                                name={activity.to_status.name}
                                color={activity.to_status.color}
                            />
                        </>
                    )}
                </span>
            );
        case 'file_uploaded':
            return (
                <span className="text-muted-foreground">
                    uploaded{' '}
                    <span className="text-foreground">
                        {activity.meta?.name}
                    </span>
                </span>
            );
        case 'file_removed':
            return (
                <span className="text-muted-foreground">
                    removed{' '}
                    <span className="text-foreground line-through">
                        {activity.meta?.name}
                    </span>
                </span>
            );
        case 'comment':
            return <span className="text-muted-foreground">left a note</span>;
    }
}

function EditDialog({
    open,
    onOpenChange,
    document,
    types,
    staff,
    companies,
    merchants,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    document: DocumentItem;
    types: Option[];
    staff: StaffMember[];
    companies: CompanyRef[];
    merchants: MerchantRef[];
}) {
    const form = useForm<DocumentFormData>({
        title: document.title,
        type: document.type,
        counterparty: document.counterparty ?? '',
        owner_id: document.owner ? String(document.owner.id) : '',
        due_date: document.due_date ?? '',
        notes: document.notes ?? '',
        company_id: document.company ? String(document.company.id) : '',
        merchant_id: document.merchant ? String(document.merchant.id) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(admin.documents.update.url(document.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Edit document</DialogTitle>
                        <DialogDescription>
                            Status changes live in the Status panel so they are
                            logged with a comment.
                        </DialogDescription>
                    </DialogHeader>
                    <DocumentFields
                        data={form.data}
                        setData={form.setData}
                        errors={form.errors}
                        types={types}
                        staff={staff}
                        companies={companies}
                        merchants={merchants}
                    />
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ArchiveButton({ document }: { document: DocumentItem }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                variant="outline"
                size="icon"
                aria-label="Archive document"
                onClick={() => setOpen(true)}
            >
                <Archive />
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Archive this document?</DialogTitle>
                        <DialogDescription>
                            “{document.title}” will disappear from the Document
                            Center. Files and history are kept in the database.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    admin.documents.destroy.url(document.id),
                                )
                            }
                        >
                            Archive
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

DocumentShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Document Center', href: admin.documents.index() },
        {
            title: props.document.title,
            href: admin.documents.show(props.document.id),
        },
    ],
});
