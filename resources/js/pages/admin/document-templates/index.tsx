import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    FileText,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { PageBody, PageHeader } from '@/components/admin/page-header';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes, formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { DocumentTemplate } from '@/types';

type Props = { templates: DocumentTemplate[] };

type Editing = DocumentTemplate | 'new' | null;

const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.zip';

export default function DocumentTemplates({ templates }: Props) {
    const [editing, setEditing] = useState<Editing>(null);

    return (
        <>
            <Head title="Document templates" />
            <PageBody>
                <Link
                    href={admin.documents.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Document Center
                </Link>
                <PageHeader
                    title="Document templates"
                    description="Blank contracts and forms kept ready for download. Upload a new version to replace a template."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Upload template
                        </Button>
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    {templates.length === 0 ? (
                        <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                            No templates yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {templates.map((template) => (
                                <li
                                    key={template.id}
                                    className="flex items-center gap-4 px-4 py-3"
                                >
                                    <FileText className="size-5 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">
                                            {template.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {template.description ??
                                                template.original_name}
                                        </p>
                                    </div>
                                    <div className="hidden text-right text-xs text-muted-foreground sm:block">
                                        <p>
                                            {template.original_name} ·{' '}
                                            {formatBytes(template.size)}
                                        </p>
                                        <p>
                                            {formatDate(template.updated_at)}
                                            {template.uploader &&
                                                ` · ${template.uploader}`}
                                        </p>
                                    </div>
                                    <div className="flex gap-1">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Download ${template.name}`}
                                            asChild
                                        >
                                            <a
                                                href={admin.documentTemplates.download.url(
                                                    template.id,
                                                )}
                                            >
                                                <Download />
                                            </a>
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Edit ${template.name}`}
                                            onClick={() => setEditing(template)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Delete ${template.name}`}
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Delete template “${template.name}”?`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        admin.documentTemplates.destroy.url(
                                                            template.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
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
                    )}
                </div>
            </PageBody>

            {editing && (
                <TemplateDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    template={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function TemplateDialog({
    template,
    onClose,
}: {
    template: DocumentTemplate | null;
    onClose: () => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm({
        name: template?.name ?? '',
        description: template?.description ?? '',
        file: null as File | null,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: onClose,
        };

        if (template) {
            // Multipart bodies are not parsed on PUT, so spoof the method.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(admin.documentTemplates.update.url(template.id), options);
        } else {
            form.post(admin.documentTemplates.store.url(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {template ? 'Edit template' : 'Upload template'}
                        </DialogTitle>
                        <DialogDescription>
                            PDF, Office, images or ZIP, up to 20 MB.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="template-name">Name</Label>
                        <Input
                            id="template-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="e.g. Merchant services agreement"
                            autoFocus
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="template-description">
                            Description
                        </Label>
                        <Textarea
                            id="template-description"
                            rows={2}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="template-file">
                            {template ? 'Replace file (optional)' : 'File'}
                        </Label>
                        <Input
                            id="template-file"
                            ref={input}
                            type="file"
                            accept={ACCEPT}
                            onChange={(e) =>
                                form.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        {template && !form.data.file && (
                            <p className="text-xs text-muted-foreground">
                                Current: {template.original_name}
                            </p>
                        )}
                        <InputError message={form.errors.file} />
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {template ? 'Save' : 'Upload'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

DocumentTemplates.layout = {
    breadcrumbs: [
        { title: 'Document Center', href: admin.documents.index() },
        { title: 'Templates', href: admin.documentTemplates.index() },
    ],
};
