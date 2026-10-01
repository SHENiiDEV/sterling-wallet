import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Download,
    ShieldCheck,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { StatCard } from '@/components/admin/stat-card';
import {
    Amounts,
    MerchantSwitcher,
    ReportFiles,
    TxLink,
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
import { formatMoney } from '@/lib/money';
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

type Props = PortalShared & {
    overview: Overview;
    recentReports: PortalReport[];
    recentSettlements: PortalSettlement[];
};

export default function PortalDashboard(props: Props) {
    const { overview, recentReports, recentSettlements } = props;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {props.company ?? 'Dashboard'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your processing, payouts and reserve — {overview.month}.
                    </p>
                </div>
                <MerchantSwitcher shared={props} url={portal.dashboard.url()} />
            </div>

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
                    hint="After fees, refunds and reserve"
                    icon={Wallet}
                    tone="success"
                />
                <StatCard
                    label="Balance to be paid"
                    value={<Amounts amounts={overview.unpaid} />}
                    hint="Earned, not in a settlement yet"
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

            <section className="rounded-xl border bg-card shadow-xs">
                <header className="flex items-center justify-between border-b px-5 py-3.5">
                    <h2 className="text-sm font-semibold">
                        Daily sales · last 30 days
                    </h2>
                    {overview.last_payout && (
                        <span className="text-xs text-muted-foreground">
                            Last payout{' '}
                            <span className="font-medium text-foreground">
                                {formatMoney(overview.last_payout.amount)}{' '}
                                {overview.last_payout.currency}
                            </span>{' '}
                            on {formatDate(overview.last_payout.date)}
                        </span>
                    )}
                </header>
                <div className="p-5">
                    <BarChart
                        points={overview.daily}
                        label={`Sales in ${overview.base_currency}`}
                        format={(v) =>
                            formatMoney(v, overview.base_currency, 0)
                        }
                    />
                </div>
            </section>

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                <header className="flex items-center justify-between border-b px-5 py-3.5">
                    <h2 className="text-sm font-semibold">
                        Latest daily reports
                    </h2>
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={portal.reports.url()}>
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
                                <TableHead>MID</TableHead>
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
                                    <TableCell className="pl-5">
                                        {formatDate(r.report_date)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {r.mid}
                                        {props.merchants.length > 1 && (
                                            <span className="block font-sans text-muted-foreground">
                                                {r.merchant}
                                            </span>
                                        )}
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
                        <Link href={portal.settlements.url()}>
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
