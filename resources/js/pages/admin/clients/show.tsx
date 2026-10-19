import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Briefcase,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Download,
    Mail,
} from 'lucide-react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';

type Money = Record<string, string>;

type Props = {
    client: {
        id: number;
        name: string;
        invoice_email: string | null;
        notes: string | null;
    };
    companies: {
        id: number;
        name: string;
        merchants: {
            public_id: string;
            name: string;
            mids: { mid: string; currency: string; status: string }[];
        }[];
    }[];
    month: string;
    days: {
        date: string;
        completed: number;
        waiting: number;
        payout: Money;
        companies: {
            id: number;
            name: string;
            reports: number;
            payout: Money;
        }[];
    }[];
};

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function Payout({ payout }: { payout: Money }) {
    const entries = Object.entries(payout);

    if (entries.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="tabular-nums">
            {entries
                .map(([currency, amount]) => formatMoney(amount, currency))
                .join(' · ')}
        </span>
    );
}

export default function ClientShow({ client, companies, month, days }: Props) {
    const reportUrl = (
        date: string,
        format: 'pdf' | 'xlsx',
        company?: number,
    ) =>
        `${admin.clients.report.url(client.id)}?date=${date}&format=${format}${company ? `&company=${company}` : ''}`;
    const monthLabel = new Date(`${month}-01T00:00:00`).toLocaleDateString(
        'en-GB',
        { month: 'long', year: 'numeric' },
    );
    const go = (next: string) =>
        router.get(
            admin.clients.show.url(client.id),
            { month: next },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title={client.name} />
            <PageBody>
                <Link
                    href={admin.clients.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Clients
                </Link>
                <PageHeader
                    title={client.name}
                    description={
                        client.invoice_email ?? 'No invoice e-mail yet'
                    }
                />

                <section className="grid gap-3">
                    <h2 className="text-sm font-semibold">Companies</h2>
                    {companies.length === 0 ? (
                        <EmptyState
                            icon={Briefcase}
                            title="No companies yet"
                            description="Assign companies to this client on the Companies page."
                        />
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {companies.map((company) => (
                                <div
                                    key={company.id}
                                    className="rounded-xl border bg-card p-4 shadow-xs"
                                >
                                    <div className="font-medium">
                                        {company.name}
                                    </div>
                                    {company.merchants.length === 0 ? (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            No merchants yet.
                                        </p>
                                    ) : (
                                        <ul className="mt-2 grid gap-2 text-sm">
                                            {company.merchants.map(
                                                (merchant) => (
                                                    <li
                                                        key={merchant.public_id}
                                                    >
                                                        <Link
                                                            href={admin.merchants.show(
                                                                merchant.public_id,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {merchant.name}
                                                        </Link>
                                                        <div className="mt-1 flex flex-wrap gap-1">
                                                            {merchant.mids.map(
                                                                (mid) => (
                                                                    <span
                                                                        key={
                                                                            mid.mid
                                                                        }
                                                                        className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs"
                                                                    >
                                                                        {
                                                                            mid.mid
                                                                        }{' '}
                                                                        {
                                                                            mid.currency
                                                                        }
                                                                    </span>
                                                                ),
                                                            )}
                                                        </div>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">Daily reports</h2>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Previous month"
                                onClick={() => go(shiftMonth(month, -1))}
                            >
                                <ChevronLeft />
                            </Button>
                            <span className="min-w-36 text-center text-sm font-medium">
                                {monthLabel}
                            </span>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Next month"
                                onClick={() => go(shiftMonth(month, 1))}
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                    </div>

                    {days.length === 0 ? (
                        <EmptyState
                            icon={CalendarDays}
                            title="No reports this month"
                            description="Daily reports of the client's companies appear here once they are built."
                        />
                    ) : (
                        <div className="grid gap-3">
                            {days.map((day) => (
                                <div
                                    key={day.date}
                                    className="rounded-xl border bg-card p-4 shadow-xs"
                                >
                                    <div className="flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="font-semibold">
                                                {formatDate(day.date)}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {day.completed}{' '}
                                                {day.completed === 1
                                                    ? 'report'
                                                    : 'reports'}
                                                {day.waiting > 0 &&
                                                    ` · ${day.waiting} waiting for data`}
                                                {' · net payout '}
                                                <Payout payout={day.payout} />
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {(['pdf', 'xlsx'] as const).map(
                                                (format) => (
                                                    <Button
                                                        key={format}
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <a
                                                            href={reportUrl(
                                                                day.date,
                                                                format,
                                                            )}
                                                        >
                                                            <Download />
                                                            All companies{' '}
                                                            {format.toUpperCase()}
                                                        </a>
                                                    </Button>
                                                ),
                                            )}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={!client.invoice_email}
                                                onClick={() =>
                                                    router.post(
                                                        admin.clients.sendReport.url(
                                                            client.id,
                                                        ),
                                                        { date: day.date },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Mail />
                                                Send
                                            </Button>
                                        </div>
                                    </div>
                                    {day.companies.length > 0 && (
                                        <ul className="mt-3 divide-y rounded-lg border text-sm">
                                            {day.companies.map((company) => (
                                                <li
                                                    key={company.id}
                                                    className="flex flex-wrap items-center gap-3 px-3 py-2"
                                                >
                                                    <span className="min-w-0 flex-1">
                                                        {company.name}
                                                        <span className="ml-2 text-xs text-muted-foreground">
                                                            {company.reports}{' '}
                                                            {company.reports ===
                                                            1
                                                                ? 'MID'
                                                                : 'MIDs'}
                                                        </span>
                                                    </span>
                                                    <Payout
                                                        payout={company.payout}
                                                    />
                                                    {(
                                                        ['pdf', 'xlsx'] as const
                                                    ).map((format) => (
                                                        <Button
                                                            key={format}
                                                            variant="ghost"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={reportUrl(
                                                                    day.date,
                                                                    format,
                                                                    company.id,
                                                                )}
                                                            >
                                                                <Download />
                                                                {format.toUpperCase()}
                                                            </a>
                                                        </Button>
                                                    ))}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </PageBody>
        </>
    );
}

ClientShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Clients', href: admin.clients.index() },
        { title: props.client.name, href: admin.clients.show(props.client.id) },
    ],
});
