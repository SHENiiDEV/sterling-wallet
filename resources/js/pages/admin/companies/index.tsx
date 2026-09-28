import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    CornerDownRight,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import admin from '@/routes/admin';
import type { Company } from '@/types';

type ParentOption = { id: number; name: string; parent_id: number | null };

type Props = {
    companies: Company[];
    parents: ParentOption[];
    filters: { search: string };
};

const NONE = 'none';

/** Orders companies as a tree: each parent followed by its subsidiaries. */
function asTree(companies: Company[]): { company: Company; depth: number }[] {
    const ids = new Set(companies.map((c) => c.id));
    const children = new Map<number | null, Company[]>();

    for (const company of companies) {
        const key =
            company.parent_id && ids.has(company.parent_id)
                ? company.parent_id
                : null;
        children.set(key, [...(children.get(key) ?? []), company]);
    }

    const rows: { company: Company; depth: number }[] = [];
    const walk = (parent: number | null, depth: number) => {
        for (const company of children.get(parent) ?? []) {
            rows.push({ company, depth });
            walk(company.id, depth + 1);
        }
    };
    walk(null, 0);

    return rows;
}

export default function CompaniesIndex({ companies, parents, filters }: Props) {
    const [editing, setEditing] = useState<Company | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Company | null>(null);
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);
    const rows = useMemo(() => asTree(companies), [companies]);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timeout = setTimeout(
            () =>
                router.get(
                    admin.companies.index.url(),
                    search ? { search } : {},
                    { preserveState: true, replace: true },
                ),
            300,
        );

        return () => clearTimeout(timeout);
    }, [search]);

    return (
        <>
            <Head title="Companies" />
            <PageBody>
                <PageHeader
                    title="Companies"
                    description="Legal entities behind your merchants. Subsidiaries sit under their parent; a company login sees only itself and its subsidiaries."
                    actions={
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            New company
                        </Button>
                    }
                />
                <PageErrors keys={['company']} />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="border-b p-3">
                        <div className="relative max-w-sm">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search name or registration no…"
                                className="pl-9"
                            />
                        </div>
                    </div>

                    {rows.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title={
                                filters.search
                                    ? 'No companies match'
                                    : 'No companies yet'
                            }
                            description="Create the legal entity first, then attach merchants to it."
                            action={
                                !filters.search && (
                                    <Button onClick={() => setEditing('new')}>
                                        <Plus />
                                        New company
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-4">
                                        Company
                                    </TableHead>
                                    <TableHead className="hidden md:table-cell">
                                        Reg. number
                                    </TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        Country
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Merchants
                                    </TableHead>
                                    <TableHead className="w-24 pr-4" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map(({ company, depth }) => (
                                    <TableRow key={company.id}>
                                        <TableCell className="pl-4">
                                            <div
                                                className="flex items-center gap-2"
                                                style={{
                                                    paddingLeft: depth * 20,
                                                }}
                                            >
                                                {depth > 0 && (
                                                    <CornerDownRight className="size-3.5 shrink-0 text-muted-foreground" />
                                                )}
                                                <span className="font-medium">
                                                    {company.name}
                                                </span>
                                                {(company.children_count ?? 0) >
                                                    0 && (
                                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                                                        {company.children_count}{' '}
                                                        sub
                                                    </span>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="hidden font-mono text-xs text-muted-foreground md:table-cell">
                                            {company.registration_number ?? '—'}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground sm:table-cell">
                                            {company.country ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {company.merchants_count ? (
                                                <Link
                                                    href={admin.merchants.index(
                                                        {
                                                            query: {
                                                                company:
                                                                    company.id,
                                                            },
                                                        },
                                                    )}
                                                    className="font-medium text-brand hover:underline"
                                                >
                                                    {company.merchants_count}
                                                </Link>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Edit ${company.name}`}
                                                onClick={() =>
                                                    setEditing(company)
                                                }
                                            >
                                                <Pencil />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label={`Delete ${company.name}`}
                                                disabled={
                                                    (company.merchants_count ??
                                                        0) > 0
                                                }
                                                onClick={() =>
                                                    setDeleting(company)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </PageBody>

            {editing && (
                <CompanyDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    company={editing === 'new' ? null : editing}
                    parents={parents}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name}?`}
                description="Subsidiaries will become top-level companies."
                onConfirm={() =>
                    deleting &&
                    router.delete(admin.companies.destroy.url(deleting.id), {
                        preserveScroll: true,
                    })
                }
            />
        </>
    );
}

function CompanyDialog({
    company,
    parents,
    onClose,
}: {
    company: Company | null;
    parents: ParentOption[];
    onClose: () => void;
}) {
    const form = useForm({
        name: company?.name ?? '',
        parent_id: company?.parent_id ? String(company.parent_id) : '',
        registration_number: company?.registration_number ?? '',
        country: company?.country ?? '',
        billing_details: company?.billing_details ?? '',
        notes: company?.notes ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (company) {
            form.put(admin.companies.update.url(company.id), options);
        } else {
            form.post(admin.companies.store.url(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {company ? 'Edit company' : 'New company'}
                        </DialogTitle>
                        <DialogDescription>
                            The legal entity that signs contracts and receives
                            statements.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Name"
                            htmlFor="name"
                            error={form.errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                autoFocus
                            />
                        </Field>
                        <Field
                            label="Parent company"
                            error={form.errors.parent_id}
                            className="sm:col-span-2"
                        >
                            <Select
                                value={form.data.parent_id || NONE}
                                onValueChange={(value) =>
                                    form.setData(
                                        'parent_id',
                                        value === NONE ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        None — top-level company
                                    </SelectItem>
                                    {parents
                                        .filter((p) => p.id !== company?.id)
                                        .map((parent) => (
                                            <SelectItem
                                                key={parent.id}
                                                value={String(parent.id)}
                                            >
                                                {parent.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Registration number"
                            htmlFor="reg"
                            error={form.errors.registration_number}
                        >
                            <Input
                                id="reg"
                                value={form.data.registration_number}
                                onChange={(e) =>
                                    form.setData(
                                        'registration_number',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Country"
                            htmlFor="country"
                            error={form.errors.country}
                            hint="ISO code, e.g. GB"
                        >
                            <Input
                                id="country"
                                value={form.data.country}
                                maxLength={2}
                                className="uppercase"
                                onChange={(e) =>
                                    form.setData('country', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Billing details"
                            htmlFor="billing"
                            error={form.errors.billing_details}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="billing"
                                rows={3}
                                value={form.data.billing_details}
                                onChange={(e) =>
                                    form.setData(
                                        'billing_details',
                                        e.target.value,
                                    )
                                }
                                placeholder="Registered address, VAT, bank details…"
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="notes"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {company ? 'Save' : 'Create company'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

CompaniesIndex.layout = {
    breadcrumbs: [{ title: 'Companies', href: admin.companies.index() }],
};
