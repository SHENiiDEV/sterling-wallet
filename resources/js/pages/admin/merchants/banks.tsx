import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Landmark, Plus } from 'lucide-react';
import { EmptyState } from '@/components/admin/empty-state';
import { acquirerColor } from '@/components/admin/merchants/acquirers-section';
import { ViewSwitch } from '@/components/admin/merchants/view-switch';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { StatusBadge } from '@/components/admin/status-badge';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';

type BankSummary = {
    id: number;
    name: string;
    is_active: boolean;
    merchants: number;
    live: number;
    mids: number;
    statuses: { status: string; label: string; count: number }[];
    preview: string[];
};

type BankRow = {
    id: number;
    merchant_id: number;
    merchant_public_id: string;
    merchant: string;
    company: string | null;
    website: string | null;
    status: string;
    status_label: string;
    mcc: string | null;
    limit: string | null;
    limit_currency: string;
    integration: string;
    psp: string | null;
    notes: string | null;
    mids: { id: number; mid: string; currency: string }[];
};

type OpenBank = {
    id: number;
    name: string;
    terms: { label: string; value: string }[];
    notes: string | null;
    rows: BankRow[];
};

const plural = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

const dash = <span className="text-muted-foreground">—</span>;

function BankCard({ bank }: { bank: BankSummary }) {
    return (
        <Link
            href={admin.merchants.banks.url({ query: { bank: bank.id } })}
            className="group flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs transition hover:border-primary/40 hover:bg-accent/40"
        >
            <div className="flex items-center gap-3">
                <Landmark className="size-8 shrink-0 text-sky-500/80 group-hover:text-sky-500" />
                <div className="min-w-0">
                    <div className="truncate font-medium">{bank.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {plural(bank.merchants, 'merchant')} ·{' '}
                        {plural(bank.mids, 'MID')}
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap gap-1.5">
                {bank.statuses.length === 0 ? (
                    <span className="text-xs text-muted-foreground">
                        No merchants yet
                    </span>
                ) : (
                    bank.statuses.map((s) => (
                        <StatusBadge
                            key={s.status}
                            name={`${s.label} · ${s.count}`}
                            color={acquirerColor(s.status)}
                        />
                    ))
                )}
            </div>
            {bank.preview.length > 0 && (
                <p className="truncate text-xs text-muted-foreground">
                    {bank.preview.join(' · ')}
                    {bank.merchants > bank.preview.length && ' …'}
                </p>
            )}
        </Link>
    );
}

export default function MerchantBanks({
    banks,
    open,
}: {
    banks: BankSummary[];
    open: OpenBank | null;
}) {
    const actions = (
        <>
            <ViewSwitch view="banks" />
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
                        description={`${plural(open.rows.length, 'merchant')} onboarded or in progress with this bank.`}
                        actions={actions}
                    />
                    <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Link
                            href={admin.merchants.banks()}
                            className="hover:text-foreground"
                        >
                            All banks
                        </Link>
                        <ChevronRight className="size-3.5" />
                        <span className="text-foreground">{open.name}</span>
                    </nav>

                    <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border sm:grid-cols-3 xl:grid-cols-5">
                        {open.terms.map((term) => (
                            <div key={term.label} className="bg-card px-4 py-3">
                                <dt className="text-xs text-muted-foreground">
                                    {term.label}
                                </dt>
                                <dd className="text-sm font-medium tabular-nums">
                                    {term.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {open.notes && (
                        <p className="text-xs whitespace-pre-line text-muted-foreground">
                            {open.notes}
                        </p>
                    )}

                    {open.rows.length === 0 ? (
                        <EmptyState
                            icon={Landmark}
                            title="No merchants with this bank"
                            description="Add the bank on a merchant's page, in Banks & onboarding."
                        />
                    ) : (
                        <div className="rounded-xl border bg-card shadow-xs">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead className="pl-5">
                                            Merchant
                                        </TableHead>
                                        <TableHead>Onboarding</TableHead>
                                        <TableHead>MCC</TableHead>
                                        <TableHead className="text-right">
                                            Limit
                                        </TableHead>
                                        <TableHead>Integration</TableHead>
                                        <TableHead>PSP</TableHead>
                                        <TableHead className="pr-5">
                                            MIDs
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {open.rows.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="pl-5">
                                                <Link
                                                    href={admin.merchants.show(
                                                        row.merchant_public_id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.merchant}
                                                </Link>
                                                <div className="text-xs text-muted-foreground">
                                                    {[row.company, row.website]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </div>
                                                {row.notes && (
                                                    <div className="mt-0.5 max-w-xs text-xs text-amber-600">
                                                        {row.notes}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    name={row.status_label}
                                                    color={acquirerColor(
                                                        row.status,
                                                    )}
                                                />
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {row.mcc ?? dash}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.limit
                                                    ? formatMoney(
                                                          row.limit,
                                                          row.limit_currency,
                                                          0,
                                                      )
                                                    : dash}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {row.integration}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {row.psp ?? dash}
                                            </TableCell>
                                            <TableCell className="pr-5">
                                                {row.mids.length === 0 ? (
                                                    dash
                                                ) : (
                                                    <div className="flex flex-col gap-1">
                                                        {row.mids.map((mid) => (
                                                            <span
                                                                key={mid.id}
                                                                className="flex items-center gap-1.5 font-mono text-xs"
                                                            >
                                                                <CurrencyBadge
                                                                    currency={
                                                                        mid.currency
                                                                    }
                                                                />
                                                                {mid.mid}
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </PageBody>
            </>
        );
    }

    return (
        <>
            <Head title="Banks" />
            <PageBody>
                <PageHeader
                    title="Merchants & MIDs"
                    description="Open a bank to see who is onboarded there, in what status, with which limit and MIDs."
                    actions={actions}
                />
                {banks.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="No banks yet"
                        description="Add acquiring banks in Providers."
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {banks.map((bank) => (
                            <BankCard key={bank.id} bank={bank} />
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

MerchantBanks.layout = {
    breadcrumbs: [{ title: 'Merchants', href: admin.merchants.folders() }],
};
