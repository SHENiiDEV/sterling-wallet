import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    Building2,
    Check,
    Copy,
    FileText,
    FlaskConical,
    KeyRound,
    Pencil,
    Plus,
    Receipt,
    ShieldCheck,
    Trash2,
    TrendingUp,
    Wallet as WalletIcon,
    WalletCards,
    Waypoints,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { DeleteMerchantDialog } from '@/components/admin/merchants/delete-merchant-dialog';
import type { MerchantDeletion } from '@/components/admin/merchants/delete-merchant-dialog';
import { AcquirersSection } from '@/components/admin/merchants/acquirers-section';
import type { Acquirer } from '@/components/admin/merchants/acquirers-section';
import { MidDialog } from '@/components/admin/merchants/mid-dialog';
import {
    ReportsTab,
    SettlementsTab,
} from '@/components/admin/merchants/merchant-tabs';
import type {
    MerchantOverview,
    MerchantReport,
    MerchantSettlement,
} from '@/components/admin/merchants/merchant-tabs';
import { PortalAccessSection } from '@/components/admin/merchants/portal-access-section';
import type {
    PortalCompany,
    PortalUser,
} from '@/components/admin/merchants/portal-access-section';
import { BarChart } from '@/components/admin/bar-chart';
import { StatCard } from '@/components/admin/stat-card';
import { Amounts } from '@/components/portal/portal';
import {
    SeedRevealDialog,
    WalletDialog,
} from '@/components/admin/merchants/wallet-dialogs';
import { PageBody } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { StatusBadge } from '@/components/admin/status-badge';
import {
    CurrencyBadge,
    MerchantStatusBadge,
    MidStatusBadge,
} from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDate } from '@/lib/format';
import { formatMoney, formatPercent } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type {
    DocumentItem,
    Merchant,
    Mid,
    Option,
    ProviderRef,
    Wallet,
} from '@/types';

const TABS = [
    ['overview', 'Overview'],
    ['mids', 'MIDs'],
    ['reports', 'Daily reports'],
    ['settlements', 'Settlements'],
    ['wallets', 'Wallets'],
    ['banks', 'Banks'],
    ['documents', 'Documents'],
    ['access', 'Portal access'],
] as const;

type TabKey = (typeof TABS)[number][0];

type Props = {
    merchant: Merchant;
    wallets: Wallet[];
    documents: DocumentItem[];
    bankProviders: ProviderRef[];
    gateProviders: ProviderRef[];
    currencies: Option[];
    midStatuses: Option[];
    walletTypes: Option[];
    canManageSeeds: boolean;
    acquirers: Acquirer[];
    acquirerStatuses: Option[];
    integrationStatuses: Option[];
    deletion?: MerchantDeletion;
    overview: MerchantOverview;
    reports: MerchantReport[];
    settlements: MerchantSettlement[];
    portalUsers: PortalUser[] | null;
    portalCompanies: PortalCompany[];
    portalUrl: string;
};

export default function MerchantShow(props: Props) {
    const { merchant, wallets, documents, canManageSeeds } = props;
    const mids = merchant.mids ?? [];
    const [editingMid, setEditingMid] = useState<Mid | 'new' | null>(null);
    const [deletingMid, setDeletingMid] = useState<Mid | null>(null);
    const [editingWallet, setEditingWallet] = useState<Wallet | 'new' | null>(
        null,
    );
    const [deletingWallet, setDeletingWallet] = useState<Wallet | null>(null);
    const [revealing, setRevealing] = useState<Wallet | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [copied, copy] = useClipboard();
    const { overview } = props;
    const [tab, setTab] = useState<TabKey>(() => {
        const fromUrl =
            typeof window !== 'undefined'
                ? new URLSearchParams(window.location.search).get('tab')
                : null;

        return TABS.some(([key]) => key === fromUrl)
            ? (fromUrl as TabKey)
            : 'overview';
    });
    // Keep the tab in the URL, so a reload or a shared link opens it again.
    const selectTab = (key: TabKey) => {
        setTab(key);
        const url = new URL(window.location.href);
        if (key === 'overview') {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', key);
        }
        window.history.replaceState(window.history.state, '', url);
    };
    const counts: Partial<Record<TabKey, number>> = {
        mids: mids.length,
        reports: props.reports.length,
        settlements: props.settlements.length,
        wallets: wallets.length,
        banks: props.acquirers.length,
        access: props.portalUsers?.length,
    };

    const midsWithoutAcquirer = mids.filter(
        (mid) => mid.status === 'active' && !mid.bank_provider_id,
    );
    const warnings = [
        merchant.status === 'active' &&
            !merchant.is_test &&
            merchant.missing_tariff.length > 0 &&
            'Card tariff is incomplete — daily reports will not be calculated until every Visa and Mastercard rate is set (or a fallback rate covers it).',
        midsWithoutAcquirer.length > 0 &&
            `${midsWithoutAcquirer.length} active MID${midsWithoutAcquirer.length > 1 ? 's have' : ' has'} no acquirer, so provider costs cannot be priced.`,
        merchant.status === 'review' &&
            'Created from an unknown MID in a report. Check the details and set a tariff before activating.',
    ].filter(Boolean) as string[];

    return (
        <>
            <Head title={merchant.name} />
            <PageBody>
                <div className="flex flex-col gap-4">
                    <Link
                        href={admin.merchants.index()}
                        className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Merchants
                    </Link>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0 space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <MerchantStatusBadge
                                    status={merchant.status}
                                    label={merchant.status_label}
                                />
                                {merchant.is_test && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                        <FlaskConical className="size-3" />
                                        Test
                                    </span>
                                )}
                                {mids.map((mid) => (
                                    <CurrencyBadge
                                        key={mid.id}
                                        currency={mid.currency}
                                    />
                                ))}
                            </div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {merchant.name}
                            </h1>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <button
                                    type="button"
                                    onClick={() => copy(merchant.public_id)}
                                    className="inline-flex items-center gap-1.5 font-mono text-xs hover:text-foreground"
                                    title="Copy public ID"
                                >
                                    {merchant.public_id}
                                    {copied === merchant.public_id ? (
                                        <Check className="size-3.5 text-success" />
                                    ) : (
                                        <Copy className="size-3.5" />
                                    )}
                                </button>
                                {merchant.company && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Building2 className="size-3.5" />
                                        {merchant.company.name}
                                    </span>
                                )}
                                {merchant.website && (
                                    <a
                                        href={`https://${merchant.website.replace(/^https?:\/\//, '')}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="hover:text-foreground hover:underline"
                                    >
                                        {merchant.website}
                                    </a>
                                )}
                                {merchant.mcc && (
                                    <span>MCC {merchant.mcc}</span>
                                )}
                                {merchant.onboarding_status && (
                                    <span>{merchant.onboarding_status}</span>
                                )}
                            </div>
                        </div>
                        <div className="flex shrink-0 gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={admin.merchants.edit(
                                        merchant.public_id,
                                    )}
                                >
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Delete merchant"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                </div>

                <PageErrors keys={['merchant', 'mid', 'currency']} />
                {warnings.map((warning) => (
                    <div
                        key={warning}
                        className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
                        {warning}
                    </div>
                ))}

                <nav className="-mb-2 flex gap-1 overflow-x-auto border-b">
                    {TABS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => selectTab(key)}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                tab === key
                                    ? 'border-brand text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                            {counts[key] !== undefined && (
                                <span className="ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground tabular-nums">
                                    {counts[key]}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard
                                label={`Sales · ${overview.month}`}
                                value={<Amounts amounts={overview.turnover} />}
                                hint={`${overview.sales} approved transactions`}
                                icon={TrendingUp}
                                tone="brand"
                            />
                            <StatCard
                                label="Net payout this month"
                                value={<Amounts amounts={overview.payout} />}
                                icon={Banknote}
                                tone="success"
                            />
                            <StatCard
                                label="Not paid out yet"
                                value={<Amounts amounts={overview.unpaid} />}
                                hint={
                                    overview.unpaid.length > 0 ? (
                                        <button
                                            type="button"
                                            className="text-brand hover:underline"
                                            onClick={() =>
                                                selectTab('settlements')
                                            }
                                        >
                                            Create a settlement →
                                        </button>
                                    ) : (
                                        'Everything is in a settlement'
                                    )
                                }
                                icon={WalletIcon}
                                tone="warning"
                            />
                            <StatCard
                                label="Reserve held"
                                value={<Amounts amounts={overview.reserve} />}
                                hint={
                                    overview.last_payout
                                        ? `Last payout ${formatMoney(overview.last_payout.amount)} ${overview.last_payout.currency} · ${formatDate(overview.last_payout.date)}`
                                        : 'No payout yet'
                                }
                                icon={ShieldCheck}
                            />
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <TariffCard merchant={merchant} />

                            <section className="rounded-xl border bg-card shadow-xs">
                                <header className="flex items-center gap-2 border-b px-5 py-3.5">
                                    <ShieldCheck className="size-4 text-muted-foreground" />
                                    <h2 className="text-sm font-semibold">
                                        Reserve & payout
                                    </h2>
                                </header>
                                <dl className="grid grid-cols-2 gap-x-6 gap-y-4 p-5 text-sm">
                                    <Stat
                                        label="Rolling reserve"
                                        value={formatPercent(
                                            merchant.rolling_reserve_percent,
                                        )}
                                    />
                                    <Stat
                                        label="Held for"
                                        value={`${merchant.rolling_reserve_days} days`}
                                    />
                                    <Stat
                                        label="Conversion fee"
                                        value={formatPercent(
                                            merchant.fee_fiat_to_crypto_percent,
                                        )}
                                    />
                                    <Stat
                                        label="Crypto provider"
                                        value={
                                            merchant.crypto_provider ??
                                            'Not set'
                                        }
                                    />
                                    <Stat
                                        label="Statements to"
                                        value={merchant.invoice_email ?? '—'}
                                        className="col-span-2"
                                    />
                                </dl>
                                {mids.length > 0 && (
                                    <div className="grid gap-3 border-t p-5">
                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Held now
                                        </p>
                                        {mids.map((mid) => (
                                            <ReserveBar
                                                key={mid.id}
                                                mid={mid}
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>

                        <section className="rounded-xl border bg-card shadow-xs">
                            <header className="border-b px-5 py-3.5">
                                <h2 className="text-sm font-semibold">
                                    Daily sales · last 30 days
                                </h2>
                            </header>
                            <div className="p-5">
                                <BarChart
                                    points={overview.daily}
                                    label={`Sales in ${overview.base_currency}`}
                                    format={(v) =>
                                        formatMoney(
                                            v,
                                            overview.base_currency,
                                            0,
                                        )
                                    }
                                />
                            </div>
                        </section>
                        {merchant.notes && (
                            <section className="rounded-xl border bg-card p-5 text-sm whitespace-pre-line shadow-xs">
                                {merchant.notes}
                            </section>
                        )}
                    </>
                )}

                {tab === 'mids' && (
                    <>
                        {/* MIDs */}
                        <section className="rounded-xl border bg-card shadow-xs">
                            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                                <div>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <Waypoints className="size-4 text-muted-foreground" />
                                        MIDs
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        One currency each — reports, reserve and
                                        payouts are tracked per MID.
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() => setEditingMid('new')}
                                >
                                    <Plus />
                                    Add MID
                                </Button>
                            </header>
                            {mids.length === 0 ? (
                                <button
                                    type="button"
                                    onClick={() => setEditingMid('new')}
                                    className="m-5 flex w-[calc(100%-2.5rem)] flex-col items-center gap-2 rounded-lg border border-dashed py-10 text-sm text-muted-foreground hover:bg-muted/40"
                                >
                                    <Waypoints className="size-5" />
                                    No MIDs yet — add one for each currency the
                                    merchant processes
                                </button>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="pl-5">
                                                MID
                                            </TableHead>
                                            <TableHead className="hidden md:table-cell">
                                                Acquirer / gateway
                                            </TableHead>
                                            <TableHead className="hidden lg:table-cell">
                                                Reserve
                                            </TableHead>
                                            <TableHead className="hidden sm:table-cell">
                                                Reports from
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-24 pr-5" />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {mids.map((mid) => (
                                            <MidRow
                                                key={mid.id}
                                                mid={mid}
                                                onEdit={() =>
                                                    setEditingMid(mid)
                                                }
                                                onDelete={() =>
                                                    setDeletingMid(mid)
                                                }
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </section>
                    </>
                )}

                {tab === 'reports' && <ReportsTab reports={props.reports} />}

                {tab === 'settlements' && (
                    <SettlementsTab
                        merchantId={merchant.public_id}
                        settlements={props.settlements}
                        unpaid={overview.unpaid}
                    />
                )}

                {tab === 'wallets' && (
                    <>
                        {/* Wallets */}
                        <section className="rounded-xl border bg-card shadow-xs">
                            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                                <h2 className="flex items-center gap-2 text-sm font-semibold">
                                    <WalletCards className="size-4 text-muted-foreground" />
                                    Crypto wallets
                                </h2>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditingWallet('new')}
                                >
                                    <Plus />
                                    Add
                                </Button>
                            </header>
                            {wallets.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                    No wallets linked yet.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {wallets.map((wallet) => (
                                        <li
                                            key={wallet.id}
                                            className={cn(
                                                'flex items-center gap-3 px-5 py-3',
                                                !wallet.is_active &&
                                                    'opacity-50',
                                            )}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="flex items-center gap-2 text-sm font-medium">
                                                    {wallet.label ??
                                                        wallet.type_label}
                                                    <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                                                        {wallet.currency}·
                                                        {wallet.network}
                                                    </span>
                                                </p>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        copy(wallet.address)
                                                    }
                                                    className="flex max-w-full items-center gap-1.5 truncate font-mono text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    <span className="truncate">
                                                        {wallet.address}
                                                    </span>
                                                    {copied ===
                                                    wallet.address ? (
                                                        <Check className="size-3 shrink-0 text-success" />
                                                    ) : (
                                                        <Copy className="size-3 shrink-0" />
                                                    )}
                                                </button>
                                            </div>
                                            {wallet.has_seed &&
                                                canManageSeeds && (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        aria-label="Reveal seed phrase"
                                                        onClick={() =>
                                                            setRevealing(wallet)
                                                        }
                                                    >
                                                        <KeyRound />
                                                    </Button>
                                                )}
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Edit wallet"
                                                onClick={() =>
                                                    setEditingWallet(wallet)
                                                }
                                            >
                                                <Pencil />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Remove wallet"
                                                onClick={() =>
                                                    setDeletingWallet(wallet)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}

                {tab === 'banks' && (
                    <>
                        <AcquirersSection
                            merchantId={merchant.public_id}
                            acquirers={props.acquirers}
                            banks={props.bankProviders}
                            statuses={props.acquirerStatuses}
                            integrationStatuses={props.integrationStatuses}
                        />
                    </>
                )}

                {tab === 'documents' && (
                    <>
                        {/* Documents */}
                        <section className="rounded-xl border bg-card shadow-xs">
                            <header className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
                                <h2 className="flex items-center gap-2 text-sm font-semibold">
                                    <FileText className="size-4 text-muted-foreground" />
                                    Documents
                                </h2>
                                <Button size="sm" variant="outline" asChild>
                                    <Link
                                        href={admin.documents.index({
                                            query: { merchant: merchant.id },
                                        })}
                                    >
                                        View all
                                    </Link>
                                </Button>
                            </header>
                            {documents.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                    No documents linked. Link them from the
                                    Document Center.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {documents.map((document) => (
                                        <li key={document.id}>
                                            <Link
                                                href={admin.documents.show(
                                                    document.id,
                                                )}
                                                className="flex items-center gap-3 px-5 py-3 hover:bg-muted/40"
                                            >
                                                <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                                    {document.title}
                                                </span>
                                                <StatusBadge
                                                    name={document.status.name}
                                                    color={
                                                        document.status.color
                                                    }
                                                />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}

                {tab === 'access' && (
                    <PortalAccessSection
                        company={merchant.company?.name ?? null}
                        companies={props.portalCompanies}
                        users={props.portalUsers}
                        portalUrl={props.portalUrl}
                    />
                )}
            </PageBody>

            {editingMid && (
                <MidDialog
                    key={editingMid === 'new' ? 'new' : editingMid.id}
                    merchantId={merchant.public_id}
                    mid={editingMid === 'new' ? null : editingMid}
                    currencies={props.currencies}
                    statuses={props.midStatuses}
                    bankProviders={props.bankProviders}
                    gateProviders={props.gateProviders}
                    onClose={() => setEditingMid(null)}
                />
            )}
            {editingWallet && (
                <WalletDialog
                    key={editingWallet === 'new' ? 'new' : editingWallet.id}
                    merchantId={merchant.public_id}
                    wallet={editingWallet === 'new' ? null : editingWallet}
                    types={props.walletTypes}
                    canManageSeeds={canManageSeeds}
                    onClose={() => setEditingWallet(null)}
                />
            )}
            {revealing && (
                <SeedRevealDialog
                    merchantId={merchant.public_id}
                    wallet={revealing}
                    onClose={() => setRevealing(null)}
                />
            )}
            <ConfirmDialog
                open={deletingMid !== null}
                onOpenChange={(open) => !open && setDeletingMid(null)}
                title={`Remove MID ${deletingMid?.mid}?`}
                description="Only possible while the MID has no operations, reports or reserve."
                onConfirm={() =>
                    deletingMid &&
                    router.delete(
                        admin.merchants.mids.destroy.url({
                            merchant: merchant.public_id,
                            mid: deletingMid.id,
                        }),
                        { preserveScroll: true },
                    )
                }
            />
            <ConfirmDialog
                open={deletingWallet !== null}
                onOpenChange={(open) => !open && setDeletingWallet(null)}
                title="Remove this wallet?"
                description={deletingWallet?.address}
                onConfirm={() =>
                    deletingWallet &&
                    router.delete(
                        admin.merchants.wallets.destroy.url({
                            merchant: merchant.public_id,
                            wallet: deletingWallet.id,
                        }),
                        { preserveScroll: true },
                    )
                }
            />
            <DeleteMerchantDialog
                merchant={merchant}
                deletion={props.deletion}
                open={deleting}
                onOpenChange={setDeleting}
            />
        </>
    );
}

function MidRow({
    mid,
    onEdit,
    onDelete,
}: {
    mid: Mid;
    onEdit: () => void;
    onDelete: () => void;
}) {
    return (
        <TableRow>
            <TableCell className="pl-5">
                <div className="flex items-center gap-2">
                    <CurrencyBadge currency={mid.currency} />
                    <span className="font-mono text-sm font-medium">
                        {mid.mid}
                    </span>
                </div>
                {(mid.label || mid.provider_login) && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {[
                            mid.label,
                            mid.provider_login && `login ${mid.provider_login}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                )}
            </TableCell>
            <TableCell className="hidden text-sm md:table-cell">
                <span className={cn(!mid.bank_provider && 'text-warning')}>
                    {mid.bank_provider ?? 'No acquirer'}
                </span>
                <span className="text-muted-foreground">
                    {' / '}
                    {mid.gate_provider ?? '—'}
                </span>
            </TableCell>
            <TableCell className="hidden w-56 lg:table-cell">
                <ReserveBar mid={mid} compact />
            </TableCell>
            <TableCell className="hidden text-sm text-muted-foreground sm:table-cell">
                {formatDate(mid.reports_start_date)}
            </TableCell>
            <TableCell>
                <MidStatusBadge status={mid.status} label={mid.status_label} />
            </TableCell>
            <TableCell className="pr-5 text-right">
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Edit MID ${mid.mid}`}
                    onClick={onEdit}
                >
                    <Pencil />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Remove MID ${mid.mid}`}
                    onClick={onDelete}
                >
                    <Trash2 />
                </Button>
            </TableCell>
        </TableRow>
    );
}

function ReserveBar({ mid, compact = false }: { mid: Mid; compact?: boolean }) {
    const balance = Number(mid.reserve_balance ?? 0);
    const limit = Number(mid.rolling_reserve_limit);
    const share = limit > 0 ? Math.min(100, (balance / limit) * 100) : 0;

    return (
        <div className="grid gap-1.5">
            <div className="flex items-baseline justify-between gap-2 text-xs">
                {!compact && (
                    <span className="flex items-center gap-1.5">
                        <CurrencyBadge currency={mid.currency} />
                        <span className="font-mono text-muted-foreground">
                            {mid.mid}
                        </span>
                    </span>
                )}
                <span className="font-mono tabular-nums">
                    {formatMoney(balance, mid.currency)}
                    <span className="text-muted-foreground">
                        {' / '}
                        {limit > 0
                            ? formatMoney(limit, mid.currency, 0)
                            : 'no limit'}
                    </span>
                </span>
            </div>
            <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                <span
                    className="block h-full rounded-full bg-brand"
                    style={{ width: `${share}%` }}
                />
            </span>
        </div>
    );
}

function TariffCard({ merchant }: { merchant: Merchant }) {
    const card = (value: string | null, fallback: string) =>
        value === null ? (
            <span className="text-muted-foreground">
                {formatPercent(fallback)}
                <span className="ml-1 text-[10px]">fallback</span>
            </span>
        ) : (
            formatPercent(value)
        );

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex items-center gap-2 border-b px-5 py-3.5">
                <Receipt className="size-4 text-muted-foreground" />
                <h2 className="text-sm font-semibold">Tariff</h2>
            </header>
            <div className="p-5">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-xs text-muted-foreground">
                            <th className="pb-2 text-left font-medium" />
                            <th className="pb-2 text-right font-medium">EU</th>
                            <th className="pb-2 text-right font-medium">
                                Non-EU
                            </th>
                        </tr>
                    </thead>
                    <tbody className="font-mono tabular-nums">
                        <tr>
                            <td className="py-1 font-sans">Visa</td>
                            <td className="py-1 text-right">
                                {card(
                                    merchant.fee_visa_eu_percent,
                                    merchant.fee_acq_eu_percent,
                                )}
                            </td>
                            <td className="py-1 text-right">
                                {card(
                                    merchant.fee_visa_non_eu_percent,
                                    merchant.fee_acq_non_eu_percent,
                                )}
                            </td>
                        </tr>
                        <tr>
                            <td className="py-1 font-sans">Mastercard</td>
                            <td className="py-1 text-right">
                                {card(
                                    merchant.fee_mastercard_eu_percent,
                                    merchant.fee_acq_eu_percent,
                                )}
                            </td>
                            <td className="py-1 text-right">
                                {card(
                                    merchant.fee_mastercard_non_eu_percent,
                                    merchant.fee_acq_non_eu_percent,
                                )}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <dl className="mt-4 grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-4">
                    <Stat
                        label="Success"
                        value={Number(merchant.fee_success_fixed).toString()}
                    />
                    <Stat
                        label="Decline"
                        value={Number(merchant.fee_decline_fixed).toString()}
                    />
                    <Stat
                        label="Refund"
                        value={Number(merchant.fee_refund_fixed).toString()}
                    />
                    <Stat
                        label="Chargeback"
                        value={Number(merchant.fee_chargeback_fixed).toString()}
                    />
                    {merchant.fee_collab_fixed !== null && (
                        <Stat
                            label="Collab"
                            value={Number(merchant.fee_collab_fixed).toString()}
                        />
                    )}
                </dl>
                <dl className="mt-4 grid grid-cols-2 gap-3 border-t pt-4 sm:grid-cols-4">
                    <Stat
                        label="Apple/Google Pay"
                        value={`+${Number(merchant.fee_wallet_percent)}%`}
                    />
                    <Stat
                        label="Settlement FX"
                        value={`${Number(merchant.fee_settlement_fx_percent)}%`}
                    />
                    <Stat
                        label="Settlement charge"
                        value={Number(merchant.fee_settlement_fixed).toString()}
                    />
                    <Stat
                        label="Max reserve"
                        value={
                            merchant.rolling_reserve_cap === null
                                ? '—'
                                : Number(
                                      merchant.rolling_reserve_cap,
                                  ).toString()
                        }
                    />
                </dl>
                <p className="mt-3 text-xs text-muted-foreground">
                    Fixed fees are charged in each MID's currency.
                    {merchant.settlement_terms &&
                        ` Settlement: ${merchant.settlement_terms}.`}
                </p>
            </div>
        </section>
    );
}

function Stat({
    label,
    value,
    className,
}: {
    label: string;
    value: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-0.5', className)}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="truncate font-medium">{value}</dd>
        </div>
    );
}

MerchantShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Merchants', href: admin.merchants.index() },
        {
            title: props.merchant.name,
            href: admin.merchants.show(props.merchant.public_id),
        },
    ],
});
