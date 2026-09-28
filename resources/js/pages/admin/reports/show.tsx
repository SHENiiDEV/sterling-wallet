import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    Lock,
    Mail,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type { StatusColor } from '@/types';

type Report = {
    id: number;
    status: string;
    report_date: string;
    period_from: string;
    period_to: string;
    currency: string;
    error: string | null;
    generated_at: string | null;
    email_sent_at: string | null;
    fx_rate: string | null;
    base_currency: string;
    sales_count: number;
    turnover: string;
    refunds_amount: string;
    chargebacks_amount: string;
    total_merchant_fee: string;
    total_provider_cost: string;
    reserve_amount: string;
    net_volume: string;
    conversion_fee: string;
    net_payout: string;
    net_profit: string;
    turnover_base: string | null;
    net_profit_base: string | null;
    summary: {
        counts?: Record<string, number>;
        merchant_fee?: { percent: string; fixed: string };
        provider_cost?: { bank: string; gate: string; crypto: string };
    } | null;
    files: string[];
};

type Props = {
    report: Report;
    merchant: { public_id: string; name: string; invoice_email: string | null };
    mid: {
        id: number;
        mid: string;
        bank_provider: string | null;
        gate_provider: string | null;
    };
    sources: {
        provider: string;
        role: string;
        rows: number;
        received_at: string;
        bot_run_id: number | null;
    }[];
    missing: string[];
    operations: { role: string; type: string; count: number }[];
    reserve: {
        type: string;
        amount: string;
        release_on: string | null;
        note: string | null;
        created_at: string | null;
    }[];
    settlements: {
        type: string;
        settlement: { id: number; number: string; status: string };
        amount: string;
    }[];
    lockReason: string | null;
};

const statusColors: Record<string, StatusColor> = {
    completed: 'emerald',
    pending: 'slate',
    partial: 'amber',
    blocked: 'rose',
    failed: 'rose',
};

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card p-5 shadow-xs">
            <h2 className="mb-3 text-sm font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Line({
    label,
    value,
    strong = false,
}: {
    label: ReactNode;
    value: ReactNode;
    strong?: boolean;
}) {
    return (
        <div className="flex justify-between gap-4 border-b py-1.5 text-sm last:border-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={
                    strong ? 'font-semibold tabular-nums' : 'tabular-nums'
                }
            >
                {value}
            </dd>
        </div>
    );
}

export default function ReportShow({
    report,
    merchant,
    mid,
    sources,
    missing,
    operations,
    reserve,
    settlements,
    lockReason,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const c = report.currency;
    const m = (v: string | null | undefined) => formatMoney(v ?? 0, c);
    const completed = report.status === 'completed';

    return (
        <>
            <Head title={`Report ${mid.mid} ${report.report_date}`} />
            <PageBody>
                <Link
                    href={admin.reports.index({
                        query: { month: report.report_date.slice(0, 7) },
                    })}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Report Control Center
                </Link>
                <PageHeader
                    title={`${mid.mid} · ${formatDate(report.report_date)}`}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <StatusBadge
                                name={report.status}
                                color={statusColors[report.status] ?? 'slate'}
                            />
                            <Link
                                href={admin.merchants.show(merchant.public_id)}
                                className="hover:underline"
                            >
                                {merchant.name}
                            </Link>
                            <span>
                                ·{' '}
                                {report.period_from === report.period_to
                                    ? formatDate(report.period_from)
                                    : `${formatDate(report.period_from)} — ${formatDate(report.period_to)}`}
                            </span>
                            <span>
                                · {mid.bank_provider ?? 'no acquirer'}
                                {mid.gate_provider && ` ↔ ${mid.gate_provider}`}
                            </span>
                        </span>
                    }
                    actions={
                        <>
                            {report.files.map((file) => (
                                <Button
                                    key={file}
                                    variant="outline"
                                    size="sm"
                                    asChild
                                >
                                    <a
                                        href={admin.reports.download.url({
                                            report: report.id,
                                            file,
                                        })}
                                    >
                                        <Download />
                                        {file === 'operations'
                                            ? 'CSV'
                                            : file.toUpperCase()}
                                    </a>
                                </Button>
                            ))}
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!completed || !merchant.invoice_email}
                                onClick={() =>
                                    router.post(
                                        admin.reports.resend.url(report.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Mail />
                                Resend
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={Boolean(lockReason)}
                                onClick={() =>
                                    router.post(
                                        admin.reports.regenerate.url(report.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <RefreshCw />
                                Regenerate
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={Boolean(lockReason)}
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 />
                                Delete
                            </Button>
                        </>
                    }
                />
                <PageErrors keys={['report']} />

                {lockReason && (
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                        <Lock className="size-4 text-muted-foreground" />
                        Locked: {lockReason} Late data will not change it.
                    </div>
                )}
                {report.error && (
                    <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm">
                        {report.error}
                    </div>
                )}
                {missing.length > 0 && (
                    <div className="rounded-lg border border-warning/40 bg-warning/5 px-4 py-3 text-sm">
                        Waiting for: <strong>{missing.join(', ')}</strong>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel title="Calculation">
                        <dl>
                            <Line
                                label={`Sales (${report.sales_count})`}
                                value={m(report.turnover)}
                            />
                            <Line
                                label={`Refunds (${report.summary?.counts?.refunds ?? 0})`}
                                value={`− ${m(report.refunds_amount)}`}
                            />
                            <Line
                                label={`Chargebacks (${report.summary?.counts?.chargebacks ?? 0})`}
                                value={`− ${m(report.chargebacks_amount)}`}
                            />
                            <Line
                                label={`Processing fee${report.summary?.merchant_fee ? ` (${m(report.summary.merchant_fee.percent)} by rate + ${m(report.summary.merchant_fee.fixed)} fixed)` : ''}`}
                                value={`− ${m(report.total_merchant_fee)}`}
                            />
                            <Line
                                label="Rolling reserve"
                                value={`− ${m(report.reserve_amount)}`}
                            />
                            <Line
                                label="Net volume"
                                value={m(report.net_volume)}
                            />
                            <Line
                                label="Conversion fee"
                                value={`− ${m(report.conversion_fee)}`}
                            />
                            <Line
                                label="Payout"
                                value={m(report.net_payout)}
                                strong
                            />
                        </dl>
                    </Panel>
                    <Panel title="Our side">
                        <dl>
                            <Line
                                label="Merchant fee + conversion"
                                value={m(
                                    String(
                                        Number(report.total_merchant_fee) +
                                            Number(report.conversion_fee),
                                    ),
                                )}
                            />
                            <Line
                                label="Acquirer cost"
                                value={`− ${m(report.summary?.provider_cost?.bank)}`}
                            />
                            <Line
                                label="Gateway cost"
                                value={`− ${m(report.summary?.provider_cost?.gate)}`}
                            />
                            <Line
                                label="Crypto cost"
                                value={`− ${m(report.summary?.provider_cost?.crypto)}`}
                            />
                            <Line
                                label="Net profit"
                                value={m(report.net_profit)}
                                strong
                            />
                            <Line
                                label={`In ${report.base_currency} (rate ${report.fx_rate ? Number(report.fx_rate) : '—'})`}
                                value={formatMoney(
                                    report.net_profit_base ?? 0,
                                    report.base_currency,
                                )}
                            />
                            <Line
                                label="Declines"
                                value={report.summary?.counts?.declines ?? 0}
                            />
                            <Line
                                label="Without pair (acquirer / gateway)"
                                value={`${report.summary?.counts?.unmatched_bank ?? 0} / ${report.summary?.counts?.unmatched_gate ?? 0}`}
                            />
                        </dl>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Generated{' '}
                            {report.generated_at
                                ? formatDateTime(report.generated_at)
                                : 'not yet'}{' '}
                            · e-mail{' '}
                            {report.email_sent_at
                                ? `sent ${formatDateTime(report.email_sent_at)}`
                                : 'not sent'}
                        </p>
                    </Panel>

                    <Panel title="Sources">
                        {sources.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No provider file yet.
                            </p>
                        ) : (
                            <dl>
                                {sources.map((s) => (
                                    <Line
                                        key={s.provider}
                                        label={`${s.provider} (${s.role === 'bank' ? 'acquirer' : 'gateway'})`}
                                        value={`${s.rows} rows · ${formatDateTime(s.received_at)}${s.bot_run_id ? ` · bot #${s.bot_run_id}` : ''}`}
                                    />
                                ))}
                            </dl>
                        )}
                        {operations.length > 0 && (
                            <p className="mt-3 text-sm">
                                <Link
                                    href={admin.operations.index({
                                        query: {
                                            search: mid.mid,
                                            from: report.period_from,
                                            to: report.period_to,
                                        },
                                    })}
                                    className="text-primary hover:underline"
                                >
                                    {operations.reduce(
                                        (sum, o) => sum + o.count,
                                        0,
                                    )}{' '}
                                    operations in this period →
                                </Link>
                            </p>
                        )}
                    </Panel>

                    <Panel title="Reserve & settlements">
                        {reserve.length === 0 && settlements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing held or paid yet.
                            </p>
                        ) : (
                            <dl>
                                {reserve.map((e, i) => (
                                    <Line
                                        key={i}
                                        label={`${e.type}${e.release_on ? ` · release ${formatDate(e.release_on)}` : ''}${e.note ? ` · ${e.note}` : ''}`}
                                        value={m(e.amount)}
                                    />
                                ))}
                                {settlements.map((s) => (
                                    <Line
                                        key={`${s.settlement.id}-${s.type}`}
                                        label={
                                            <Link
                                                href={admin.settlements.show(
                                                    s.settlement.id,
                                                )}
                                                className="hover:underline"
                                            >
                                                {s.settlement.number} ·{' '}
                                                {s.settlement.status} ·{' '}
                                                {s.type === 'report'
                                                    ? 'payout'
                                                    : 'reserve release'}
                                            </Link>
                                        }
                                        value={m(s.amount)}
                                    />
                                ))}
                            </dl>
                        )}
                    </Panel>
                </div>
            </PageBody>
            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title="Delete this report?"
                description="Its rolling reserve is reversed. Operations stay, so the report is rebuilt when the next file arrives or a bot runs again."
                onConfirm={() =>
                    router.delete(admin.reports.destroy.url(report.id))
                }
            />
        </>
    );
}

ReportShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Report Control Center', href: admin.reports.index() },
        {
            title: `${props.mid.mid} · ${props.report.report_date}`,
            href: admin.reports.show(props.report.id),
        },
    ],
});
