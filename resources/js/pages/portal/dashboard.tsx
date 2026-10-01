import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Banknote,
    Building2,
    Download,
    ShieldCheck,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { StatCard } from '@/components/admin/stat-card';
import {
    Amounts,
    PortalFilters,
    ReportFiles,
    TxLink,
    withCompany,
} from '@/components/portal/portal';
import type {
    Amount,
    PortalReport,
    PortalSettlement,
    PortalShared,
} from '@/components/portal/portal';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { formatMoney, formatPercent } from '@/lib/money';
import portal from '@/routes/portal';

type Overview = {
    month: string;
    sales: number;
    turnover: Amount[];
    payout: Amount[];
    unpaid: Amount[];
    reserve: Amount[];
    last_payout: {
        number: string;
        amount: string;
        currency: string;
        date: string | null;
    } | null;
    daily: { date: string; value: number }[];
    base_currency: string;
};

type Analytics = {
    currency: string;
    months: {
        month: string;
        label: string;
        turnover: number;
        payout: number;
        sales: number;
    }[];
    this_month: {
        turnover: number;
        payout: number;
        sales: number;
        previous_turnover: number | null;
    };
    companies: {
        id: number;
        name: string;
        merchants: number;
        turnover: number;
        payout: number;
        sales: number;
    }[];
};

type Pricing = {
    public_id: string;
    name: string;
    company: string | null;
    status: string;
    website: string | null;
    rates: [string, string | null, string | null][];
    fixed: [string, string][];
    conversion_percent: string;
    reserve_percent: string;
    reserve_days: number;
    mids: { mid: string; currency: string; status: string }[];
};

type Props = PortalShared & {
    overview: Overview;
    analytics: Analytics;
    pricing: Pricing[];
    recentReports: PortalReport[];
    recentSettlements: PortalSettlement[];
};

const monthLabel = (date: string) =>
    new Intl.DateTimeFormat('en-GB', {
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${date.slice(0, 7)}-01T12:00:00`));

export default function PortalDashboard(props: Props) {
    const { overview, analytics, pricing, recentReports, recentSettlements } =
        props;
    const scope = props.portal;
    const groupView = scope.company === null && scope.companies.length > 1;
    const base = analytics.currency;
    const money = (v: number) => formatMoney(v, base, 0);
    const maxCompany = Math.max(
        1,
        ...analytics.companies.map((c) => c.turnover),
    );

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p className="text-sm text-muted-foreground">
                        {scope.company ? scope.root : 'Overview'}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {scope.company?.name ?? scope.root ?? 'Dashboard'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {groupView
                            ? `${scope.companies.length} companies · ${overview.month}`
                            : `Your processing, payouts and pricing · ${overview.month}`}
                    </p>
                </div>
                <PortalFilters shared={props} url={portal.dashboard.url()} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label={`Sales · ${overview.month}`}
                    value={<Amounts amounts={overview.turnover} />}
                    hint={`${overview.sales} approved transactions${analytics.this_month.previous_turnover !== null ? ` · last month ${money(analytics.this_month.previous_turnover)}` : ''}`}
                    icon={TrendingUp}
                    tone="brand"
                />
                <StatCard
                    label="Net payout this month"
                    value={<Amounts amounts={overview.payout} />}
                    hint="After fees, refunds and reserve"
                    icon={Banknote}
                    tone="success"
                />
                <StatCard
                    label="Balance to be paid"
                    value={<Amounts amounts={overview.unpaid} />}
                    hint={
                        overview.last_payout
                            ? `Last payout ${formatMoney(overview.last_payout.amount)} ${overview.last_payout.currency} · ${formatDate(overview.last_payout.date)}`
                            : 'Earned, not in a settlement yet'
                    }
                    icon={Wallet}
                    tone="warning"
                />
                <StatCard
                    label="Rolling reserve held"
                    value={<Amounts amounts={overview.reserve} />}
                    hint="Released automatically when due"
                    icon={ShieldCheck}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <ChartCard title={`Sales by month · ${base}`}>
                    <BarChart
                        points={analytics.months.map((m) => ({
                            date: `${m.month}-01`,
                            value: m.turnover,
                        }))}
                        label={`Monthly sales in ${base}`}
                        format={money}
                        formatLabel={monthLabel}
                    />
                </ChartCard>
                <ChartCard title={`Net payout by month · ${base}`}>
                    <BarChart
                        points={analytics.months.map((m) => ({
                            date: `${m.month}-01`,
                            value: m.payout,
                        }))}
                        label={`Monthly net payout in ${base}`}
                        format={money}
                        formatLabel={monthLabel}
                    />
                </ChartCard>
            </div>

            {groupView && (
                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="flex items-center justify-between border-b px-5 py-3.5">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <Building2 className="size-4 text-muted-foreground" />
                            Companies · last 30 days
                        </h2>
                        <span className="text-xs text-muted-foreground">
                            in {base} · click a company to open it
                        </span>
                    </header>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Company</TableHead>
                                <TableHead className="hidden w-1/3 md:table-cell" />
                                <TableHead className="text-right">
                                    Sales
                                </TableHead>
                                <TableHead className="text-right">
                                    Net payout
                                </TableHead>
                                <TableHead className="w-10 pr-5" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {analytics.companies.map((c) => (
                                <TableRow
                                    key={c.id}
                                    className="cursor-pointer"
                                    onClick={() =>
                                        router.get(portal.dashboard.url(), {
                                            company: c.id,
                                        })
                                    }
                                >
                                    <TableCell className="pl-5">
                                        <span className="font-medium">
                                            {c.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {c.merchants} merchant
                                            {c.merchants === 1
                                                ? ''
                                                : 's'} ·{' '}
                                            {c.sales} sales
                                        </span>
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <span className="block h-2 overflow-hidden rounded-full bg-muted">
                                            <span
                                                className="block h-full rounded-full bg-brand"
                                                style={{
                                                    width: `${(c.turnover / maxCompany) * 100}%`,
                                                }}
                                            />
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(c.turnover)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {money(c.payout)}
                                    </TableCell>
                                    <TableCell className="pr-5 text-muted-foreground">
                                        <ArrowRight className="size-4" />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            )}

            <ChartCard title="Daily sales · last 30 days">
                <BarChart
                    points={overview.daily}
                    label={`Daily sales in ${overview.base_currency}`}
                    format={(v) => formatMoney(v, overview.base_currency, 0)}
                />
            </ChartCard>

            {pricing.length > 0 && (
                <section className="grid gap-4">
                    <h2 className="text-sm font-semibold">Your pricing</h2>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {pricing.map((m) => (
                            <PricingCard key={m.public_id} merchant={m} />
                        ))}
                    </div>
                </section>
            )}

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                <header className="flex items-center justify-between border-b px-5 py-3.5">
                    <h2 className="text-sm font-semibold">
                        Latest daily reports
                    </h2>
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={withCompany(portal.reports.url(), props)}>
                            All reports
                            <ArrowRight />
                        </Link>
                    </Button>
                </header>
                {recentReports.length === 0 ? (
                    <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                        No daily reports yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Date</TableHead>
                                <TableHead>Merchant · MID</TableHead>
                                <TableHead className="text-right">
                                    Sales
                                </TableHead>
                                <TableHead className="text-right">
                                    Net payout
                                </TableHead>
                                <TableHead className="pr-5 text-right">
                                    Files
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recentReports.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="pl-5 whitespace-nowrap">
                                        {formatDate(r.report_date)}
                                    </TableCell>
                                    <TableCell>
                                        <span className="block text-sm">
                                            {r.merchant}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {r.mid}
                                            {groupView &&
                                                r.company &&
                                                ` · ${r.company}`}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(r.turnover, r.currency)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatMoney(r.net_payout, r.currency)}
                                    </TableCell>
                                    <TableCell className="pr-5">
                                        <ReportFiles report={r} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                <header className="flex items-center justify-between border-b px-5 py-3.5">
                    <h2 className="text-sm font-semibold">Settlements</h2>
                    <Button size="sm" variant="ghost" asChild>
                        <Link
                            href={withCompany(portal.settlements.url(), props)}
                        >
                            All settlements
                            <ArrowRight />
                        </Link>
                    </Button>
                </header>
                {recentSettlements.length === 0 ? (
                    <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                        No settlements yet.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {recentSettlements.map((s) => (
                            <li
                                key={s.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                            >
                                <span className="font-medium">{s.number}</span>
                                <span className="text-muted-foreground">
                                    {s.merchant}
                                    {' · '}
                                    {s.status === 'settled'
                                        ? `Paid ${formatDate(s.settled_at)}`
                                        : `Approved ${formatDate(s.approved_at)}`}
                                </span>
                                <TxLink settlement={s} />
                                <span className="ml-auto font-medium tabular-nums">
                                    {formatMoney(s.total_payout)}{' '}
                                    {s.payout_currency}
                                </span>
                                <Button size="sm" variant="ghost" asChild>
                                    <a href={portal.settlements.pdf.url(s.id)}>
                                        <Download />
                                        PDF
                                    </a>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}

function ChartCard({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="border-b px-5 py-3.5">
                <h2 className="text-sm font-semibold">{title}</h2>
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

function PricingCard({ merchant }: { merchant: Pricing }) {
    return (
        <div className="rounded-xl border bg-card shadow-xs">
            <div className="flex items-start justify-between gap-3 border-b px-5 py-3.5">
                <div className="min-w-0">
                    <p className="truncate font-medium">{merchant.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {[merchant.company, merchant.website, merchant.status]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <div className="flex flex-wrap justify-end gap-1">
                    {merchant.mids.map((mid) => (
                        <span
                            key={mid.mid}
                            className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                        >
                            {mid.currency} · {mid.mid}
                        </span>
                    ))}
                </div>
            </div>
            <div className="grid gap-4 p-5 text-sm sm:grid-cols-2">
                <table className="w-full">
                    <thead>
                        <tr className="text-xs text-muted-foreground">
                            <th className="pb-1.5 text-left font-medium">
                                Card rate
                            </th>
                            <th className="pb-1.5 text-right font-medium">
                                EU
                            </th>
                            <th className="pb-1.5 text-right font-medium">
                                Non-EU
                            </th>
                        </tr>
                    </thead>
                    <tbody className="tabular-nums">
                        {merchant.rates.map(([scheme, eu, nonEu]) => (
                            <tr key={scheme}>
                                <td className="py-1">{scheme}</td>
                                <td className="py-1 text-right">
                                    {eu === null ? 'N/A' : formatPercent(eu)}
                                </td>
                                <td className="py-1 text-right">
                                    {nonEu === null
                                        ? 'N/A'
                                        : formatPercent(nonEu)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <dl className="grid gap-1">
                    {merchant.fixed.map(([label, value]) => (
                        <div key={label} className="flex justify-between gap-3">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="tabular-nums">
                                {Number(value) > 0
                                    ? formatMoney(value)
                                    : 'Free'}
                            </dd>
                        </div>
                    ))}
                </dl>
                <dl className="grid grid-cols-2 gap-3 border-t pt-3 sm:col-span-2">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Rolling reserve
                        </dt>
                        <dd className="font-medium">
                            {formatPercent(merchant.reserve_percent)} ·{' '}
                            {merchant.reserve_days} days
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Conversion to USDC
                        </dt>
                        <dd className="font-medium">
                            {formatPercent(merchant.conversion_percent)}
                        </dd>
                    </div>
                </dl>
                <p className="text-xs text-muted-foreground sm:col-span-2">
                    Fixed fees are charged in each MID's currency.
                </p>
            </div>
        </div>
    );
}
