import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    ChevronRight,
    Folder,
    FolderOpen,
    Plus,
    Store,
} from 'lucide-react';
import { EmptyState } from '@/components/admin/empty-state';
import { ViewSwitch } from '@/components/admin/merchants/view-switch';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import {
    CurrencyBadge,
    MerchantStatusBadge,
    MidStatusBadge,
} from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';
import type { MerchantStatus, MidStatus } from '@/types';

type FolderSummary = {
    key: string;
    name: string;
    companies: number;
    merchants: number;
    mids: number;
    active_mids: number;
    currencies: string[];
    preview: string[];
};

type FolderMid = {
    id: number;
    mid: string;
    currency: string;
    status: MidStatus;
    status_label: string;
    bank: string | null;
    gate: string | null;
    gate_accounts: number;
};

type FolderMerchant = {
    id: number;
    name: string;
    website: string | null;
    status: MerchantStatus;
    status_label: string;
    mids: FolderMid[];
};

type OpenFolder = FolderSummary & {
    groups: {
        id: number | null;
        name: string;
        parent: string | null;
        merchants: FolderMerchant[];
    }[];
};

type Props = {
    folders: FolderSummary[];
    open: OpenFolder | null;
};

const plural = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

const folderUrl = (key: string) =>
    admin.merchants.folders.url({ query: { client: key } });

function FolderCard({ folder }: { folder: FolderSummary }) {
    return (
        <Link
            href={folderUrl(folder.key)}
            className="group flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs transition hover:border-primary/40 hover:bg-accent/40"
        >
            <div className="flex items-center gap-3">
                <Folder className="size-9 shrink-0 fill-current text-amber-500/80 group-hover:text-amber-500" />
                <div className="min-w-0">
                    <div className="truncate font-medium">{folder.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {plural(folder.companies, 'company', 'companies')} ·{' '}
                        {plural(folder.merchants, 'merchant')}
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-xs text-muted-foreground tabular-nums">
                    {folder.active_mids}/{plural(folder.mids, 'MID')} active
                </span>
                {folder.currencies.map((currency) => (
                    <CurrencyBadge key={currency} currency={currency} />
                ))}
            </div>
            {folder.preview.length > 0 && (
                <p className="truncate text-xs text-muted-foreground">
                    {folder.preview.join(' · ')}
                    {folder.merchants > folder.preview.length && ' …'}
                </p>
            )}
        </Link>
    );
}

function MerchantCard({ merchant }: { merchant: FolderMerchant }) {
    return (
        <div className="rounded-lg border bg-card shadow-xs">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-3">
                <Store className="size-4 text-muted-foreground" />
                <Link
                    href={admin.merchants.show(merchant.id)}
                    className="font-medium hover:underline"
                >
                    {merchant.name}
                </Link>
                {merchant.website && (
                    <span className="text-xs text-muted-foreground">
                        {merchant.website}
                    </span>
                )}
                <span className="ml-auto">
                    <MerchantStatusBadge
                        status={merchant.status}
                        label={merchant.status_label}
                    />
                </span>
            </div>
            {merchant.mids.length === 0 ? (
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    No MIDs yet.
                </p>
            ) : (
                <ul className="divide-y">
                    {merchant.mids.map((mid) => (
                        <li
                            key={mid.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm"
                        >
                            <CurrencyBadge currency={mid.currency} />
                            <span className="font-mono">{mid.mid}</span>
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                {mid.bank ?? (
                                    <span className="text-amber-600">
                                        no bank
                                    </span>
                                )}
                                <ArrowRight className="size-3" />
                                {mid.gate ?? 'no gateway'}
                                {mid.gate_accounts > 1 &&
                                    ` (${mid.gate_accounts} accounts)`}
                            </span>
                            <span className="ml-auto">
                                <MidStatusBadge
                                    status={mid.status}
                                    label={mid.status_label}
                                />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function MerchantFolders({ folders, open }: Props) {
    const switcher = (
        <>
            <ViewSwitch view="clients" />
            <Button asChild>
                <Link href={admin.merchants.create()}>
                    <Plus />
                    New merchant
                </Link>
            </Button>
        </>
    );

    if (open) {
        return (
            <>
                <Head title={open.name} />
                <PageBody>
                    <PageHeader
                        title={open.name}
                        description={`${plural(open.companies, 'company', 'companies')} · ${plural(open.merchants, 'merchant')} · ${open.active_mids}/${plural(open.mids, 'MID')} active`}
                        actions={switcher}
                    />
                    <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Link
                            href={admin.merchants.folders()}
                            className="hover:text-foreground"
                        >
                            All clients
                        </Link>
                        <ChevronRight className="size-3.5" />
                        <span className="flex items-center gap-1.5 text-foreground">
                            <FolderOpen className="size-4 text-amber-500" />
                            {open.name}
                        </span>
                    </nav>
                    {open.groups.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title="Nothing in this folder"
                            description="Add a company to this client, then a merchant under it."
                        />
                    ) : (
                        open.groups.map((group) => (
                            <section
                                key={group.id ?? 'none'}
                                className="grid gap-3"
                            >
                                <h2 className="flex items-center gap-2 text-sm font-semibold">
                                    <Building2 className="size-4 text-muted-foreground" />
                                    {group.name}
                                    {group.parent && (
                                        <span className="text-xs font-normal text-muted-foreground">
                                            under {group.parent}
                                        </span>
                                    )}
                                </h2>
                                {group.merchants.length === 0 ? (
                                    <p className="rounded-lg border border-dashed px-4 py-4 text-sm text-muted-foreground">
                                        No merchants.
                                    </p>
                                ) : (
                                    <div className="grid gap-3 xl:grid-cols-2">
                                        {group.merchants.map((merchant) => (
                                            <MerchantCard
                                                key={merchant.id}
                                                merchant={merchant}
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>
                        ))
                    )}
                </PageBody>
            </>
        );
    }

    return (
        <>
            <Head title="Merchants" />
            <PageBody>
                <PageHeader
                    title="Merchants & MIDs"
                    description="Open a client to see its companies, merchants and MIDs."
                    actions={switcher}
                />
                {folders.length === 0 ? (
                    <EmptyState
                        icon={Folder}
                        title="No clients yet"
                        description="Create a client first, then attach companies and merchants to it."
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {folders.map((folder) => (
                            <FolderCard key={folder.key} folder={folder} />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

MerchantFolders.layout = {
    breadcrumbs: [{ title: 'Merchants', href: admin.merchants.folders() }],
};
