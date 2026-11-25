import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';

type Neighbour = { id: number; rate_date: string; rate: string };

type Props = {
    rate: {
        id: number;
        rate_date: string;
        base: string;
        quote: string;
        rate: string;
        source: string;
        created_by: string | null;
        change_percent: number | null;
        created_at: string | null;
        updated_at: string | null;
        inverse: number | null;
    };
    previous: Neighbour | null;
    next: Neighbour | null;
    journal: {
        id: number;
        at: string | null;
        action: string;
        user: string | null;
        meta: Record<string, unknown> | null;
    }[];
    reportsCount: number;
    reports: {
        id: number;
        report_date: string;
        mid: string;
        currency: string;
        fx_rate: string | null;
    }[];
};

const actionLabels: Record<string, string> = {
    'fx_rate.fetched': 'Fetched from the API',
    'fx_rate.created': 'Added by hand',
    'fx_rate.updated': 'Changed by hand',
    'fx_rate.deleted': 'Removed',
};

const dateTime = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('en-GB', { hour12: false }) : '—';

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{value}</dd>
        </div>
    );
}

export default function FxRateShow({
    rate,
    previous,
    next,
    journal,
    reportsCount,
    reports,
}: Props) {
    return (
        <>
            <Head title={`${rate.base} → ${rate.quote} · ${rate.rate_date}`} />
            <PageBody>
                <Link
                    href={admin.fxRates.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    FX rates
                </Link>
                <PageHeader
                    title={`${rate.base} → ${rate.quote}`}
                    description={`${formatDate(rate.rate_date)} · 1 ${rate.base} = ${Number(rate.rate)} ${rate.quote}`}
                    actions={
                        <div className="flex items-center gap-2 text-sm">
                            {previous && (
                                <Link
                                    href={admin.fxRates.show(previous.id)}
                                    className="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 hover:bg-muted"
                                >
                                    <ChevronLeft className="size-4" />
                                    {formatDate(previous.rate_date)}
                                </Link>
                            )}
                            {next && (
                                <Link
                                    href={admin.fxRates.show(next.id)}
                                    className="inline-flex items-center gap-1 rounded-md border px-2.5 py-1.5 hover:bg-muted"
                                >
                                    {formatDate(next.rate_date)}
                                    <ChevronRight className="size-4" />
                                </Link>
                            )}
                        </div>
                    }
                />

                <section className="grid gap-4 rounded-xl border bg-card p-5 shadow-xs">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Rate" value={Number(rate.rate)} />
                        <Detail
                            label={`Inverse (1 ${rate.quote})`}
                            value={`${rate.inverse ?? '—'} ${rate.base}`}
                        />
                        <Detail
                            label="vs previous day"
                            value={
                                rate.change_percent == null
                                    ? '—'
                                    : `${rate.change_percent > 0 ? '+' : ''}${rate.change_percent}%${previous ? ` (was ${Number(previous.rate)})` : ''}`
                            }
                        />
                        <Detail
                            label="Source"
                            value={
                                rate.source +
                                (rate.created_by ? ` · ${rate.created_by}` : '')
                            }
                        />
                        <Detail
                            label="Stored"
                            value={dateTime(rate.created_at)}
                        />
                        <Detail
                            label="Last changed"
                            value={dateTime(rate.updated_at)}
                        />
                    </dl>
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="text-sm font-semibold">Journal</h2>
                        <p className="text-xs text-muted-foreground">
                            Who stored, changed or removed this rate, and what
                            it was before.
                        </p>
                    </header>
                    {journal.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Nothing recorded: the rate was stored before the
                            journal was kept.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">When</TableHead>
                                    <TableHead>What</TableHead>
                                    <TableHead>By</TableHead>
                                    <TableHead className="pr-5">
                                        Details
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journal.map((entry) => {
                                    const before = entry.meta?.before as
                                        | { rate?: string; source?: string }
                                        | null
                                        | undefined;

                                    return (
                                        <TableRow key={entry.id}>
                                            <TableCell className="pl-5 whitespace-nowrap">
                                                {dateTime(entry.at)}
                                            </TableCell>
                                            <TableCell>
                                                {actionLabels[entry.action] ??
                                                    entry.action}
                                            </TableCell>
                                            <TableCell>
                                                {entry.user ?? 'system'}
                                            </TableCell>
                                            <TableCell className="pr-5 font-mono text-xs">
                                                {before
                                                    ? `${Number(before.rate)} (${before.source}) → ${Number(entry.meta?.rate)}`
                                                    : entry.meta?.rate
                                                      ? String(
                                                            Number(
                                                                entry.meta.rate,
                                                            ),
                                                        )
                                                      : ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    )}
                </section>

                <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <header className="border-b px-5 py-3.5">
                        <h2 className="text-sm font-semibold">
                            Reports converted at this rate
                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                {reportsCount} from this day until the next rate
                                {reportsCount > 20 && ', latest 20'}
                            </span>
                        </h2>
                    </header>
                    {reports.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            No report used it.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">
                                        Report day
                                    </TableHead>
                                    <TableHead>MID</TableHead>
                                    <TableHead className="pr-5 text-right">
                                        Rate in the report
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {reports.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="pl-5">
                                            <Link
                                                href={admin.reports.show(r.id)}
                                                className="hover:underline"
                                            >
                                                {formatDate(r.report_date)}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {r.mid} · {r.currency}
                                        </TableCell>
                                        <TableCell className="pr-5 text-right font-mono tabular-nums">
                                            {r.fx_rate
                                                ? Number(r.fx_rate)
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </PageBody>
        </>
    );
}

FxRateShow.layout = {
    breadcrumbs: [{ title: 'FX rates', href: admin.fxRates.index() }],
};
