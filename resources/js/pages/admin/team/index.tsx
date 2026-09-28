import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { StatusBadge } from '@/components/admin/status-badge';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { formatRelative } from '@/lib/format';
import admin from '@/routes/admin';
import type { Option } from '@/types';

type Member = {
    id: number;
    name: string;
    email: string;
    role: 'super_admin' | 'admin';
    role_label: string;
    permissions: string[];
    is_active: boolean;
    two_factor: boolean;
    last_login_at: string | null;
    is_me: boolean;
};

type Props = {
    members: Member[];
    modules: Option[];
    roles: Option[];
    canManageSuperAdmins: boolean;
};

function MemberDialog({
    member,
    modules,
    roles,
    canManageSuperAdmins,
    onClose,
}: {
    member: Member | null;
    modules: Option[];
    roles: Option[];
    canManageSuperAdmins: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        name: member?.name ?? '',
        email: member?.email ?? '',
        role: member?.role ?? 'admin',
        permissions: member?.permissions ?? [],
        is_active: member?.is_active ?? true,
    });
    const { data, setData, errors } = form;
    const superAdmin = data.role === 'super_admin';

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <form
                    className="grid gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const options = {
                            preserveScroll: true,
                            onSuccess: onClose,
                        };
                        if (member) {
                            form.put(admin.team.update.url(member.id), options);
                        } else {
                            form.post(admin.team.store.url(), options);
                        }
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {member ? member.name : 'Add team member'}
                        </DialogTitle>
                        <DialogDescription>
                            {member
                                ? 'Change role and module access.'
                                : 'A password is generated and shown to you once.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Name"
                            htmlFor="m-name"
                            error={errors.name}
                        >
                            <Input
                                id="m-name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                autoFocus={!member}
                            />
                        </Field>
                        <Field
                            label="E-mail"
                            htmlFor="m-email"
                            error={errors.email}
                        >
                            <Input
                                id="m-email"
                                type="email"
                                value={data.email}
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Role" error={errors.role}>
                            <Select
                                value={data.role}
                                onValueChange={(v) =>
                                    setData('role', v as Member['role'])
                                }
                                disabled={member?.is_me}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles
                                        .filter(
                                            (r) =>
                                                canManageSuperAdmins ||
                                                r.value !== 'super_admin',
                                        )
                                        .map((r) => (
                                            <SelectItem
                                                key={r.value}
                                                value={r.value}
                                            >
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        {member && (
                            <label className="flex items-center gap-3 self-end pb-2 text-sm">
                                <Checkbox
                                    checked={data.is_active}
                                    disabled={member.is_me}
                                    onCheckedChange={(c) =>
                                        setData('is_active', c === true)
                                    }
                                />
                                Active — can sign in
                            </label>
                        )}
                    </div>
                    <Field label="Module access" error={errors.permissions}>
                        {superAdmin ? (
                            <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                Super admins have every module. The list below
                                applies to admins only.
                            </p>
                        ) : (
                            <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                                {modules.map((m) => (
                                    <label
                                        key={m.value}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={data.permissions.includes(
                                                m.value,
                                            )}
                                            onCheckedChange={(on) =>
                                                setData(
                                                    'permissions',
                                                    on === true
                                                        ? [
                                                              ...data.permissions,
                                                              m.value,
                                                          ]
                                                        : data.permissions.filter(
                                                              (p) =>
                                                                  p !== m.value,
                                                          ),
                                                )
                                            }
                                        />
                                        {m.label}
                                    </label>
                                ))}
                            </div>
                        )}
                        {!superAdmin && data.permissions.length === 0 && (
                            <p className="text-xs text-warning">
                                No module ticked = no access beyond the
                                dashboard.
                            </p>
                        )}
                    </Field>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {member ? 'Save' : 'Create'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CredentialsDialog({
    credentials,
    onClose,
}: {
    credentials: { email: string; password: string };
    onClose: () => void;
}) {
    const [copied, copy] = useClipboard();

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Login details</DialogTitle>
                    <DialogDescription>
                        Shown once. Pass them on securely; the person should
                        change the password after signing in.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-1 rounded-lg border bg-muted/40 p-3 font-mono text-sm">
                    <span>{credentials.email}</span>
                    <span className="break-all">{credentials.password}</span>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() =>
                            void copy(
                                `${credentials.email}\n${credentials.password}`,
                            )
                        }
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function TeamIndex({
    members,
    modules,
    roles,
    canManageSuperAdmins,
}: Props) {
    const [editing, setEditing] = useState<Member | 'new' | null>(null);
    const [credentials, setCredentials] = useState<{
        email: string;
        password: string;
    } | null>(null);
    const moduleLabel = (value: string) =>
        modules.find((m) => m.value === value)?.label ?? value;

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash;
                if (flash?.credentials) {
                    setCredentials(flash.credentials);
                }
            }),
        [],
    );

    return (
        <>
            <Head title="Team & access" />
            <PageBody>
                <PageHeader
                    title="Team & access"
                    description="Staff of the console and the modules they can open. Super admins are defined by role alone; an admin with no modules sees only the dashboard. Merchant logins are not listed here."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Add member
                        </Button>
                    }
                />
                <PageErrors keys={['role', 'member', 'permissions']} />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Member</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Access</TableHead>
                                <TableHead>Last login</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {members.map((m) => (
                                <TableRow
                                    key={m.id}
                                    className={
                                        m.is_active ? undefined : 'opacity-60'
                                    }
                                >
                                    <TableCell>
                                        <div className="font-medium">
                                            {m.name}
                                            {m.is_me && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    (you)
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {m.email}
                                            {m.two_factor && (
                                                <span className="ml-2 inline-flex items-center gap-0.5">
                                                    <ShieldCheck className="size-3" />
                                                    2FA
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            name={
                                                m.is_active
                                                    ? m.role_label
                                                    : 'Deactivated'
                                            }
                                            color={
                                                !m.is_active
                                                    ? 'slate'
                                                    : m.role === 'super_admin'
                                                      ? 'violet'
                                                      : 'blue'
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="max-w-md text-sm">
                                        {m.role === 'super_admin' ? (
                                            <span className="text-muted-foreground">
                                                Everything
                                            </span>
                                        ) : m.permissions.length === 0 ? (
                                            <span className="text-warning">
                                                No modules
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {m.permissions
                                                    .map(moduleLabel)
                                                    .join(', ')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {m.last_login_at
                                            ? formatRelative(m.last_login_at)
                                            : 'Never'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex justify-end gap-1">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Reset password"
                                                title="Reset password"
                                                disabled={
                                                    m.role === 'super_admin' &&
                                                    !canManageSuperAdmins
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        admin.team.password.url(
                                                            m.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <KeyRound />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Edit"
                                                disabled={
                                                    m.role === 'super_admin' &&
                                                    !canManageSuperAdmins
                                                }
                                                onClick={() => setEditing(m)}
                                            >
                                                <Pencil />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </PageBody>
            {editing && (
                <MemberDialog
                    member={editing === 'new' ? null : editing}
                    modules={modules}
                    roles={roles}
                    canManageSuperAdmins={canManageSuperAdmins}
                    onClose={() => setEditing(null)}
                />
            )}
            {credentials && (
                <CredentialsDialog
                    credentials={credentials}
                    onClose={() => setCredentials(null)}
                />
            )}
        </>
    );
}

TeamIndex.layout = {
    breadcrumbs: [{ title: 'Team & access', href: admin.team.index() }],
};
