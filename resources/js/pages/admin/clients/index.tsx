import { Head, Link, router, useForm } from '@inertiajs/react';
import { Briefcase, Download, Mail, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EmptyState } from '@/components/admin/empty-state';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import admin from '@/routes/admin';
import type { Client } from '@/types';

type Props = { clients: Client[]; defaultDate: string };

type Editing = Client | 'new' | null;

export default function ClientsIndex({ clients, defaultDate }: Props) {
    const [editing, setEditing] = useState<Editing>(null);
    const [deleting, setDeleting] = useState<Client | null>(null);
    const [date, setDate] = useState(defaultDate);

    return (
        <>
            <Head title="Clients" />
            <PageBody>
                <PageHeader
                    title="Clients"
                    description="One client can own several companies. Its daily report merges the reports of all of them."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            New client
                        </Button>
                    }
                />
                <PageErrors keys={['client']} />

                {clients.length === 0 ? (
                    <EmptyState
                        icon={Briefcase}
                        title="No clients yet"
                        description="Create a client, then assign its companies on the Companies page."
                    />
                ) : (
                    <>
                        <div className="flex items-center gap-2 text-sm">
                            <label htmlFor="report-date">Report date</label>
                            <Input
                                id="report-date"
                                type="date"
                                className="w-44"
                                value={date}
                                onChange={(e) => setDate(e.target.value)}
                            />
                        </div>

                        <div className="grid gap-3">
                            {clients.map((client) => (
                                <section
                                    key={client.id}
                                    className="rounded-xl border bg-card p-4 shadow-xs"
                                >
                                    <div className="flex flex-wrap items-start gap-3">
                                        <div className="min-w-0 flex-1">
                                            <h2 className="font-semibold">
                                                <Link
                                                    href={admin.clients.show(
                                                        client.id,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {client.name}
                                                </Link>
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                {client.invoice_email ??
                                                    'No invoice e-mail'}
                                            </p>
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {client.companies.length ===
                                                0 ? (
                                                    <Link
                                                        href={admin.companies.index()}
                                                        className="text-xs text-brand hover:underline"
                                                    >
                                                        Assign companies
                                                    </Link>
                                                ) : (
                                                    client.companies.map(
                                                        (company) => (
                                                            <span
                                                                key={company.id}
                                                                className="rounded-full bg-muted px-2 py-0.5 text-xs"
                                                            >
                                                                {company.name}
                                                            </span>
                                                        ),
                                                    )
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {(['pdf', 'xlsx'] as const).map(
                                                (format) => (
                                                    <Button
                                                        key={format}
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={`${admin.clients.report.url(client.id)}?date=${date}&format=${format}`}
                                                        >
                                                            <Download />
                                                            {format.toUpperCase()}
                                                        </a>
                                                    </Button>
                                                ),
                                            )}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={!client.invoice_email}
                                                onClick={() =>
                                                    router.post(
                                                        admin.clients.sendReport.url(
                                                            client.id,
                                                        ),
                                                        { date },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Mail />
                                                Send
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Edit ${client.name}`}
                                                onClick={() =>
                                                    setEditing(client)
                                                }
                                            >
                                                <Pencil />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Delete ${client.name}`}
                                                onClick={() =>
                                                    setDeleting(client)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>
                                </section>
                            ))}
                        </div>
                    </>
                )}
            </PageBody>

            {editing && (
                <ClientDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    client={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name}?`}
                description="Its companies stay, without a client."
                onConfirm={() =>
                    deleting &&
                    router.delete(admin.clients.destroy.url(deleting.id), {
                        preserveScroll: true,
                    })
                }
            />
        </>
    );
}

function ClientDialog({
    client,
    onClose,
}: {
    client: Client | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: client?.name ?? '',
        invoice_email: client?.invoice_email ?? '',
        notes: client?.notes ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (client) {
            form.put(admin.clients.update.url(client.id), options);
        } else {
            form.post(admin.clients.store.url(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {client ? 'Edit client' : 'New client'}
                        </DialogTitle>
                        <DialogDescription>
                            The consolidated daily report goes to the invoice
                            e-mail.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Name"
                        htmlFor="client-name"
                        error={form.errors.name}
                    >
                        <Input
                            id="client-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            autoFocus
                        />
                    </Field>
                    <Field
                        label="Invoice e-mail"
                        htmlFor="client-email"
                        error={form.errors.invoice_email}
                    >
                        <Input
                            id="client-email"
                            type="email"
                            value={form.data.invoice_email}
                            onChange={(e) =>
                                form.setData('invoice_email', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Notes"
                        htmlFor="client-notes"
                        error={form.errors.notes}
                    >
                        <Textarea
                            id="client-notes"
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
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
                            {form.processing && <Spinner />}
                            {client ? 'Save' : 'Create client'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

ClientsIndex.layout = {
    breadcrumbs: [{ title: 'Clients', href: admin.clients.index() }],
};
