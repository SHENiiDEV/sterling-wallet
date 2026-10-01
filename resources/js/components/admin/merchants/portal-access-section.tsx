import { router, useForm } from '@inertiajs/react';
import {
    Check,
    Copy,
    ExternalLink,
    KeyRound,
    Pencil,
    Plus,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { Field } from '@/components/admin/form';
import { PageErrors } from '@/components/admin/page-errors';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDate, formatRelative } from '@/lib/format';
import admin from '@/routes/admin';

export type PortalUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
};

/** 14 characters without look-alikes (0/O, 1/l). */
function generatePassword(): string {
    const chars =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
    const values = crypto.getRandomValues(new Uint32Array(14));

    return Array.from(values, (v) => chars[v % chars.length]).join('');
}

function UserDialog({
    merchantId,
    user,
    portalUrl,
    onClose,
}: {
    merchantId: string;
    user: PortalUser | null;
    portalUrl: string;
    onClose: () => void;
}) {
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: user ? '' : generatePassword(),
        is_active: user?.is_active ?? true,
    });
    const [copied, copy] = useClipboard();
    const credentials = `${portalUrl}\nLogin: ${form.data.email}\nPassword: ${form.data.password}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: onClose,
                        };
                        if (user) {
                            form.put(
                                admin.merchants.portalUsers.update.url({
                                    merchant: merchantId,
                                    user: user.id,
                                }),
                                options,
                            );
                        } else {
                            form.post(
                                admin.merchants.portalUsers.store.url(
                                    merchantId,
                                ),
                                options,
                            );
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {user ? user.email : 'Give portal access'}
                        </DialogTitle>
                        <DialogDescription>
                            The user signs in at {portalUrl} and sees the daily
                            reports and settlements of every merchant of this
                            company.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        label="Name"
                        htmlFor="pu-name"
                        error={form.errors.name}
                    >
                        <Input
                            id="pu-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="E-mail (login)"
                        htmlFor="pu-email"
                        error={form.errors.email}
                    >
                        <Input
                            id="pu-email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label={
                            user
                                ? 'New password (leave empty to keep)'
                                : 'Password'
                        }
                        htmlFor="pu-password"
                        error={form.errors.password}
                    >
                        <div className="flex gap-2">
                            <Input
                                id="pu-password"
                                className="font-mono"
                                value={form.data.password}
                                onChange={(e) =>
                                    form.setData('password', e.target.value)
                                }
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Generate password"
                                onClick={() =>
                                    form.setData('password', generatePassword())
                                }
                            >
                                <KeyRound />
                            </Button>
                        </div>
                    </Field>
                    {user && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_active}
                                onCheckedChange={(on) =>
                                    form.setData('is_active', on === true)
                                }
                            />
                            Access active
                        </label>
                    )}
                    {form.data.password && form.data.email && (
                        <button
                            type="button"
                            onClick={() => copy(credentials)}
                            className="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-left text-xs text-muted-foreground hover:bg-muted/50"
                        >
                            {copied === credentials ? (
                                <Check className="size-3.5 text-success" />
                            ) : (
                                <Copy className="size-3.5" />
                            )}
                            Copy link, login and password to send to the
                            merchant
                        </button>
                    )}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {user ? 'Save' : 'Create access'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function PortalAccessSection({
    merchantId,
    company,
    users,
    portalUrl,
}: {
    merchantId: string;
    company: string | null;
    users: PortalUser[] | null;
    portalUrl: string;
}) {
    const [editing, setEditing] = useState<PortalUser | 'new' | null>(null);
    const [deleting, setDeleting] = useState<PortalUser | null>(null);

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <UserRound className="size-4 text-muted-foreground" />
                        Merchant portal access
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {company
                            ? `Logins for ${company}: they see daily reports and settlements of all its merchants — never our costs or profit.`
                            : 'Portal access is given per company.'}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button size="sm" variant="ghost" asChild>
                        <a href={portalUrl} target="_blank" rel="noreferrer">
                            {portalUrl.replace(/^https?:\/\//, '')}
                            <ExternalLink />
                        </a>
                    </Button>
                    {users !== null && (
                        <Button size="sm" onClick={() => setEditing('new')}>
                            <Plus />
                            Add user
                        </Button>
                    )}
                </div>
            </header>
            <div className="px-5 pt-3 empty:hidden">
                <PageErrors keys={['portal']} />
            </div>
            {users === null ? (
                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                    Link this merchant to a company first (Edit → Company).
                </p>
            ) : users.length === 0 ? (
                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                    Nobody from {company} has portal access yet.
                </p>
            ) : (
                <ul className="divide-y">
                    {users.map((user) => (
                        <li
                            key={user.id}
                            className="flex items-center gap-3 px-5 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="flex items-center gap-2 text-sm font-medium">
                                    {user.name}
                                    {!user.is_active && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                            disabled
                                        </span>
                                    )}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {user.email} · since{' '}
                                    {formatDate(user.created_at)} ·{' '}
                                    {user.last_login_at
                                        ? `last login ${formatRelative(user.last_login_at)}`
                                        : 'never logged in'}
                                </p>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="Edit user"
                                onClick={() => setEditing(user)}
                            >
                                <Pencil />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="Remove access"
                                onClick={() => setDeleting(user)}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {editing && (
                <UserDialog
                    merchantId={merchantId}
                    user={editing === 'new' ? null : editing}
                    portalUrl={portalUrl}
                    onClose={() => setEditing(null)}
                />
            )}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Remove access for ${deleting?.email}?`}
                description="The login is deleted; reports and settlements are not affected."
                onConfirm={() =>
                    deleting &&
                    router.delete(
                        admin.merchants.portalUsers.destroy.url({
                            merchant: merchantId,
                            user: deleting.id,
                        }),
                        {
                            preserveScroll: true,
                            onSuccess: () => setDeleting(null),
                        },
                    )
                }
            />
        </section>
    );
}
