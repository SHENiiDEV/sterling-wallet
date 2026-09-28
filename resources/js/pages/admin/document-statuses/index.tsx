import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    Flag,
    Pencil,
    Plus,
    Star,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import {
    StatusBadge,
    statusColorClasses,
} from '@/components/admin/status-badge';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type { DocumentStatus, StatusColor } from '@/types';

type Props = {
    statuses: DocumentStatus[];
    colors: StatusColor[];
};

type Editing = DocumentStatus | 'new' | null;

export default function DocumentStatuses({ statuses, colors }: Props) {
    const [editing, setEditing] = useState<Editing>(null);
    const { errors } = usePage().props as { errors: Record<string, string> };

    const payload = (status: DocumentStatus, sortOrder: number) => ({
        name: status.name,
        color: status.color,
        sort_order: sortOrder,
        is_default: status.is_default,
        is_final: status.is_final,
    });

    const move = (index: number, direction: -1 | 1) => {
        const current = statuses[index];
        const neighbour = statuses[index + direction];

        if (!neighbour) {
            return;
        }

        // Normalise to spaced positions first so equal sort orders still swap.
        const orderOf = (i: number) => (i + 1) * 10;
        router.put(
            admin.documentStatuses.update.url(current.id),
            payload(current, orderOf(index + direction)),
            {
                preserveScroll: true,
                onSuccess: () =>
                    router.put(
                        admin.documentStatuses.update.url(neighbour.id),
                        payload(neighbour, orderOf(index)),
                        { preserveScroll: true },
                    ),
            },
        );
    };

    return (
        <>
            <Head title="Document statuses" />
            <PageBody>
                <Link
                    href={admin.documents.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Document Center
                </Link>
                <PageHeader
                    title="Document statuses"
                    description="The pipeline every document moves through. Order here is the order shown everywhere."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            New status
                        </Button>
                    }
                />

                {errors.status && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive-foreground">
                        {errors.status}
                    </div>
                )}

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <ul className="divide-y">
                        {statuses.map((status, index) => (
                            <li
                                key={status.id}
                                className="flex items-center gap-4 px-4 py-3"
                            >
                                <div className="flex flex-col">
                                    <button
                                        type="button"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                        className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-25"
                                        aria-label={`Move ${status.name} up`}
                                    >
                                        <ArrowUp className="size-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={index === statuses.length - 1}
                                        onClick={() => move(index, 1)}
                                        className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-25"
                                        aria-label={`Move ${status.name} down`}
                                    >
                                        <ArrowDown className="size-3.5" />
                                    </button>
                                </div>

                                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                    <StatusBadge
                                        name={status.name}
                                        color={status.color}
                                    />
                                    {status.is_default && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Star className="size-3" />
                                            Default for new
                                        </span>
                                    )}
                                    {status.is_final && (
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Flag className="size-3" />
                                            Final
                                        </span>
                                    )}
                                </div>

                                <span className="text-sm text-muted-foreground tabular-nums">
                                    {status.documents_count ?? 0} docs
                                </span>

                                <div className="flex gap-1">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Edit ${status.name}`}
                                        onClick={() => setEditing(status)}
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Delete ${status.name}`}
                                        disabled={
                                            (status.documents_count ?? 0) > 0
                                        }
                                        title={
                                            (status.documents_count ?? 0) > 0
                                                ? 'Used by documents'
                                                : undefined
                                        }
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Delete status “${status.name}”?`,
                                                )
                                            ) {
                                                router.delete(
                                                    admin.documentStatuses.destroy.url(
                                                        status.id,
                                                    ),
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="text-sm text-muted-foreground">
                    <strong className="font-medium text-foreground">
                        Final
                    </strong>{' '}
                    statuses (e.g. Signed, Cancelled) close the document: it is
                    no longer counted as open or overdue.
                </p>
            </PageBody>

            {editing && (
                <StatusDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    status={editing === 'new' ? null : editing}
                    colors={colors}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function StatusDialog({
    status,
    colors,
    onClose,
}: {
    status: DocumentStatus | null;
    colors: StatusColor[];
    onClose: () => void;
}) {
    const form = useForm({
        name: status?.name ?? '',
        color: status?.color ?? ('blue' as StatusColor),
        sort_order: status?.sort_order ?? null,
        is_default: status?.is_default ?? false,
        is_final: status?.is_final ?? false,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (status) {
            form.put(admin.documentStatuses.update.url(status.id), options);
        } else {
            form.post(admin.documentStatuses.store.url(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {status ? 'Edit status' : 'New status'}
                        </DialogTitle>
                        <DialogDescription>
                            Preview:{' '}
                            <StatusBadge
                                name={form.data.name || 'Status name'}
                                color={form.data.color}
                                className="ml-1 align-middle"
                            />
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="status-name">Name</Label>
                        <Input
                            id="status-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="e.g. Passed to merchant"
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Colour</Label>
                        <div className="flex flex-wrap gap-2">
                            {colors.map((color) => (
                                <button
                                    key={color}
                                    type="button"
                                    onClick={() => form.setData('color', color)}
                                    className={cn(
                                        'flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition',
                                        form.data.color === color &&
                                            'ring-2 ring-foreground/60',
                                    )}
                                    aria-label={color}
                                    aria-pressed={form.data.color === color}
                                >
                                    <span
                                        className={cn(
                                            'size-6 rounded-full',
                                            statusColorClasses[color].dot,
                                        )}
                                    />
                                </button>
                            ))}
                        </div>
                        <InputError message={form.errors.color} />
                    </div>

                    <div className="grid gap-3">
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={form.data.is_default}
                                onCheckedChange={(checked) =>
                                    form.setData('is_default', checked === true)
                                }
                            />
                            <span>
                                <span className="font-medium">
                                    Default for new documents
                                </span>
                                <span className="block text-muted-foreground">
                                    Only one status can be the default.
                                </span>
                            </span>
                        </label>
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={form.data.is_final}
                                onCheckedChange={(checked) =>
                                    form.setData('is_final', checked === true)
                                }
                            />
                            <span>
                                <span className="font-medium">
                                    Final status
                                </span>
                                <span className="block text-muted-foreground">
                                    The document is done — not open, never
                                    overdue.
                                </span>
                            </span>
                        </label>
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {status ? 'Save' : 'Create status'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

DocumentStatuses.layout = {
    breadcrumbs: [
        { title: 'Document Center', href: admin.documents.index() },
        { title: 'Statuses', href: admin.documentStatuses.index() },
    ],
};
