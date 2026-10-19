import { router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, Trash2, UserRound } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { Field } from '@/components/admin/form';
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
import { formatRelative } from '@/lib/format';
import admin from '@/routes/admin';

export type ClientPortalUser = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    last_login_at: string | null;
};

/** 14 characters without look-alikes (0/O, 1/l). */
function generatePassword(): string {
    const chars =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
    const values = crypto.getRandomValues(new Uint32Array(14));

    return Array.from(values, (v) => chars[v % chars.length]).join('');
}

function UserDialog({
    clientId,
    user,
    onClose,
}: {
    clientId: number;
    user: ClientPortalUser | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: user ? '' : generatePassword(),
        is_active: user?.is_active ?? true,
    });
    const { data, setData, errors, processing } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (user) {
            form.put(
                admin.clients.portalUsers.update.url({
                    client: clientId,
                    user: user.id,
                }),
                options,
            );
        } else {
            form.post(admin.clients.portalUsers.store.url(clientId), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {user ? 'Edit portal login' : 'New portal login'}
                        </DialogTitle>
                        <DialogDescription>
                            Sees every company of the client and downloads the
                            daily statement for all of them, or for one.
                        </DialogDescription>
                    </DialogHeader>
                    <Field label="Name" htmlFor="pu-name" error={errors.name}>
                        <Input
                            id="pu-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                    </Field>
                    <Field
                        label="E-mail (login)"
                        htmlFor="pu-email"
                        error={errors.email}
                    >
                        <Input
                            id="pu-email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>
                    <Field
                        label={user ? 'New password' : 'Password'}
                        htmlFor="pu-password"
                        error={errors.password}
                        hint={
                            user
                                ? 'Leave empty to keep the current one'
                                : 'Generated: pass it on to the client; it is not shown again'
                        }
                    >
                        <div className="flex gap-2">
                            <Input
                                id="pu-password"
                                className="font-mono"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                                autoComplete="off"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    setData('password', generatePassword())
                                }
                            >
                                <KeyRound />
                                New
                            </Button>
                        </div>
                    </Field>
                    {user && (
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            Active
                        </label>
                    )}
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {user ? 'Save' : 'Create login'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Portal logins of the whole client (all its companies).
 */
export function ClientPortalAccess({
    clientId,
    users,
}: {
    clientId: number;
    users: ClientPortalUser[];
}) {
    const [editing, setEditing] = useState<ClientPortalUser | 'new' | null>(
        null,
    );
    const [removing, setRemoving] = useState<ClientPortalUser | null>(null);

    return (
        <section className="grid gap-3">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Portal access</h2>
                <Button size="sm" onClick={() => setEditing('new')}>
                    <Plus />
                    New login
                </Button>
            </div>
            {users.length === 0 ? (
                <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                    No client-level logins. Create one to give the client a
                    single login for all its companies.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border bg-card shadow-xs">
                    {users.map((user) => (
                        <li
                            key={user.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                        >
                            <UserRound className="size-4 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <div className="font-medium">
                                    {user.name}
                                    {!user.is_active && (
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            disabled
                                        </span>
                                    )}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {user.email} ·{' '}
                                    {user.last_login_at
                                        ? `last login ${formatRelative(user.last_login_at)}`
                                        : 'never logged in'}
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Edit"
                                onClick={() => setEditing(user)}
                            >
                                <Pencil />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Remove"
                                onClick={() => setRemoving(user)}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {editing !== null && (
                <UserDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    clientId={clientId}
                    user={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.email}?`}
                description="They can no longer sign in to the portal."
                onConfirm={() =>
                    removing &&
                    router.delete(
                        admin.clients.portalUsers.destroy.url({
                            client: clientId,
                            user: removing.id,
                        }),
                        { preserveScroll: true },
                    )
                }
            />
        </section>
    );
}
