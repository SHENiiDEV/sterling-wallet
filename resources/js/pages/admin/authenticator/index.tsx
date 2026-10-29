import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    AuthenticatorCodeCard,
    useLiveCodes,
} from '@/components/admin/authenticator-codes';
import type { AuthenticatorCodeMeta } from '@/components/admin/authenticator-codes';
import { AuthenticatorLinkPicker } from '@/components/admin/authenticator-link-picker';
import type { LinkTargets } from '@/components/admin/authenticator-link-picker';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { EmptyState } from '@/components/admin/empty-state';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import admin from '@/routes/admin';

type Targets = LinkTargets;

type Props = {
    codes: AuthenticatorCodeMeta[];
    targets: Targets;
};

const TARGET_LABELS: Record<string, string> = {
    client: 'Client',
    company: 'Company',
    merchant: 'Merchant',
    provider: 'Provider',
};

function CodeDialog({
    code,
    targets,
    onClose,
}: {
    code: AuthenticatorCodeMeta | null;
    targets: Targets;
    onClose: () => void;
}) {
    const form = useForm({
        name: code?.name ?? '',
        service: code?.service ?? '',
        secret: '',
        notes: code?.notes ?? '',
        is_active: code?.is_active ?? true,
        links: (code?.links ?? []).map((l) => ({ type: l.type, id: l.id })),
    });
    const { data, setData, errors } = form;

    const nameOf = (linkType: string, id: number) =>
        targets[linkType]?.find((t) => t.id === id)?.name ?? `#${id}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: onClose,
                        };
                        if (code) {
                            form.put(
                                admin.authenticator.update.url(code.id),
                                options,
                            );
                        } else {
                            form.post(admin.authenticator.store.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {code ? code.name : 'Add authenticator code'}
                        </DialogTitle>
                        <DialogDescription>
                            The secret is stored encrypted and never shown
                            again: only the six-digit code it produces.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Name"
                            htmlFor="ac-name"
                            error={errors.name}
                        >
                            <Input
                                id="ac-name"
                                placeholder="Oxen · COSMIC BAGEL LTD"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Platform"
                            htmlFor="ac-service"
                            error={errors.service}
                        >
                            <Input
                                id="ac-service"
                                placeholder="Oxen, Madfin, mailbox…"
                                value={data.service}
                                onChange={(e) =>
                                    setData('service', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={code ? 'New secret (optional)' : 'Secret'}
                            htmlFor="ac-secret"
                            error={errors.secret}
                            hint="The key under the QR code (JBSW Y3DP …), or the whole otpauth:// link"
                            className="sm:col-span-2"
                        >
                            <Input
                                id="ac-secret"
                                autoComplete="off"
                                spellCheck={false}
                                value={data.secret}
                                onChange={(e) =>
                                    setData('secret', e.target.value)
                                }
                                placeholder={
                                    code
                                        ? 'Leave empty to keep the stored one'
                                        : ''
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="ac-notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="ac-notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <div className="grid gap-2">
                        <div className="text-sm font-medium">Shown with</div>
                        <p className="text-xs text-muted-foreground">
                            The code appears on the settlement of a linked
                            client, company or merchant (and of one that is paid
                            through a linked provider).
                        </p>
                        {data.links.length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {data.links.map((l) => (
                                    <span
                                        key={`${l.type}-${l.id}`}
                                        className="inline-flex items-center gap-1 rounded-md border bg-muted/40 px-2 py-0.5 text-xs"
                                    >
                                        <span className="text-muted-foreground">
                                            {TARGET_LABELS[l.type]}
                                        </span>
                                        {nameOf(l.type, l.id)}
                                        <button
                                            type="button"
                                            aria-label="Remove link"
                                            onClick={() =>
                                                setData(
                                                    'links',
                                                    data.links.filter(
                                                        (x) =>
                                                            !(
                                                                x.type ===
                                                                    l.type &&
                                                                x.id === l.id
                                                            ),
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                        )}
                        <AuthenticatorLinkPicker
                            targets={targets}
                            value={data.links}
                            onChange={(links) => setData('links', links)}
                        />
                        {errors.links && (
                            <p className="text-xs text-destructive">
                                {errors.links}
                            </p>
                        )}
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={data.is_active}
                            onCheckedChange={(v) =>
                                setData('is_active', v === true)
                            }
                        />
                        Active (an inactive code is not shown or generated)
                    </label>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function AuthenticatorIndex({ codes, targets }: Props) {
    const [editing, setEditing] = useState<
        AuthenticatorCodeMeta | 'new' | null
    >(null);
    const [deleting, setDeleting] = useState<AuthenticatorCodeMeta | null>(
        null,
    );
    const [search, setSearch] = useState('');
    const live = useLiveCodes(
        useMemo(
            () => codes.filter((c) => c.is_active).map((c) => c.id),
            [codes],
        ),
    );

    const shown = codes.filter((c) =>
        `${c.name} ${c.service ?? ''} ${c.links.map((l) => l.name).join(' ')}`
            .toLowerCase()
            .includes(search.trim().toLowerCase()),
    );

    return (
        <>
            <Head title="Authenticator codes" />
            <PageBody>
                <PageHeader
                    title="Authenticator codes"
                    description="Log-in codes for the platforms the team uses (Oxen, mailboxes, banks). The secrets are kept encrypted; the codes are shown here and next to the payouts they belong to."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Add code
                        </Button>
                    }
                />

                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search by name, platform or linked record…"
                    className="max-w-md"
                />

                {codes.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        title="No codes yet"
                        description="Add the secret of a platform's authenticator to get its code here."
                    />
                ) : (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3">
                        {shown.map((meta) =>
                            meta.is_active ? (
                                <AuthenticatorCodeCard
                                    key={meta.id}
                                    meta={meta}
                                    live={live[meta.id]}
                                    actions={
                                        <>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label="Edit"
                                                onClick={() => setEditing(meta)}
                                            >
                                                <Pencil />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                aria-label="Remove"
                                                onClick={() =>
                                                    setDeleting(meta)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </>
                                    }
                                />
                            ) : (
                                <div
                                    key={meta.id}
                                    className="flex items-center justify-between gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
                                >
                                    <span>{meta.name} · inactive</span>
                                    <span className="flex">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                            aria-label="Edit"
                                            onClick={() => setEditing(meta)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7"
                                            aria-label="Remove"
                                            onClick={() => setDeleting(meta)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </span>
                                </div>
                            ),
                        )}
                    </div>
                )}

                {editing && (
                    <CodeDialog
                        key={editing === 'new' ? 'new' : editing.id}
                        code={editing === 'new' ? null : editing}
                        targets={targets}
                        onClose={() => setEditing(null)}
                    />
                )}
                <ConfirmDialog
                    open={deleting !== null}
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Remove ${deleting?.name ?? 'code'}?`}
                    description="The secret is deleted. You can only get it back from the platform itself."
                    onConfirm={() =>
                        deleting &&
                        router.delete(
                            admin.authenticator.destroy.url(deleting.id),
                            {
                                preserveScroll: true,
                                onSuccess: () => setDeleting(null),
                            },
                        )
                    }
                />
            </PageBody>
        </>
    );
}
