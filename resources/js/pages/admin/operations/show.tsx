import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Link2, Link2Off } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    CardLabel,
    OperationTypeBadge,
    RoleLabel,
} from '@/components/admin/operation-badges';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type { Operation } from '@/types';

type Props = {
    operation: Operation;
    raw: Record<string, string | number | null>;
    fees: Record<string, string>;
    charges: {
        merchant_percent: string;
        merchant_fee: string;
        provider_percent: string;
        provider_cost: string;
    } | null;
    pair: Operation | null;
    mid: {
        mid: string;
        currency: string;
        bank_provider: string | null;
        gate_provider: string | null;
    } | null;
    report: { id: number; report_date: string; status: string } | null;
};

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b py-2 text-sm last:border-0">
            <dt className="shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-right break-all">
                {children ?? <span className="text-muted-foreground">—</span>}
            </dd>
        </div>
    );
}

function Panel({
    title,
    actions,
    children,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card p-5 shadow-xs">
            <div className="mb-2 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{title}</h2>
                {actions}
            </div>
            {children}
        </section>
    );
}

function OperationFacts({ op }: { op: Operation }) {
    return (
        <dl>
            <Row label="Provider">
                {op.provider?.name} · <RoleLabel role={op.role} />
            </Row>
            <Row label="Payment ID">
                <span className="font-mono text-xs">{op.payment_id}</span>
            </Row>
            <Row label="Pair ID (sp_id)">
                {op.sp_id && (
                    <span className="font-mono text-xs">{op.sp_id}</span>
                )}
            </Row>
            <Row label="ARN">
                {op.arn && <span className="font-mono text-xs">{op.arn}</span>}
            </Row>
            <Row label="RRN / auth code">
                {op.rrn || op.approval_code
                    ? [op.rrn, op.approval_code].filter(Boolean).join(' / ')
                    : null}
            </Row>
            <Row label="Card">
                <CardLabel bin={op.card_bin} last4={op.card_last4} />
            </Row>
            <Row label="Scheme / region">
                {[op.ips, op.region === 'non_eu' ? 'non-EU' : op.region]
                    .filter(Boolean)
                    .join(' · ') || null}
            </Row>
            <Row label="Issuer">
                {[op.issuer_name, op.issuer_country]
                    .filter(Boolean)
                    .join(' · ') || null}
            </Row>
            <Row label="E-mail">{op.customer_email}</Row>
            <Row label="trn_type / status">
                {[op.trn_type, op.processing_code, op.resolution]
                    .filter(Boolean)
                    .join(' · ') || null}
            </Row>
            <Row label="Time (UTC)">
                {op.transaction_at ? formatDateTime(op.transaction_at) : null}
            </Row>
            <Row label="Report date">{formatDate(op.report_date)}</Row>
            <Row label="Amount">
                <span className="font-semibold tabular-nums">
                    {formatMoney(op.amount, op.currency)}
                </span>
            </Row>
        </dl>
    );
}

export default function OperationShow({
    operation,
    raw,
    fees,
    charges,
    pair,
    mid,
    report,
}: Props) {
    const rawEntries = Object.entries(raw);
    const feeEntries = Object.entries(fees);

    return (
        <>
            <Head title={`Operation ${operation.payment_id ?? operation.id}`} />
            <PageBody>
                <Link
                    href={admin.operations.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Operations
                </Link>
                <PageHeader
                    title={formatMoney(operation.amount, operation.currency)}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <OperationTypeBadge
                                type={operation.operation_type}
                                label={operation.operation_type_label}
                            />
                            {mid && (
                                <span>
                                    MID{' '}
                                    <span className="font-mono">{mid.mid}</span>{' '}
                                    · {mid.bank_provider ?? 'no acquirer'}
                                    {mid.gate_provider &&
                                        ` ↔ ${mid.gate_provider}`}
                                </span>
                            )}
                            {operation.merchant && (
                                <Link
                                    href={admin.merchants.show(
                                        operation.merchant.public_id,
                                    )}
                                    className="hover:underline"
                                >
                                    {operation.merchant.name}
                                </Link>
                            )}
                            {report && (
                                <span>
                                    · report {formatDate(report.report_date)} (
                                    {report.status})
                                </span>
                            )}
                        </span>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    <Panel title="This operation">
                        <OperationFacts op={operation} />
                    </Panel>
                    <Panel
                        title="Pair in the other system"
                        actions={
                            pair ? (
                                <Link
                                    href={admin.operations.show(pair.id)}
                                    className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    <Link2 className="size-3.5" />
                                    Open
                                </Link>
                            ) : null
                        }
                    >
                        {pair ? (
                            <OperationFacts op={pair} />
                        ) : (
                            <div className="flex flex-col items-center gap-2 py-10 text-center text-sm text-muted-foreground">
                                <Link2Off className="size-5" />
                                {operation.operation_type === 'decline'
                                    ? 'Declines never reach clearing, so they have no pair.'
                                    : mid?.gate_provider
                                      ? 'Not matched yet. Reconciliation runs every 15 minutes and on report generation.'
                                      : 'This MID has no gateway — nothing to pair with.'}
                            </div>
                        )}
                    </Panel>

                    <Panel title="Charges">
                        {charges ? (
                            <dl>
                                <Row label="Merchant fee">
                                    {formatMoney(
                                        charges.merchant_fee,
                                        operation.currency,
                                    )}{' '}
                                    <span className="text-muted-foreground">
                                        ({charges.merchant_percent}%)
                                    </span>
                                </Row>
                                <Row label="Provider cost">
                                    {formatMoney(
                                        charges.provider_cost,
                                        operation.currency,
                                    )}{' '}
                                    <span className="text-muted-foreground">
                                        ({charges.provider_percent}%)
                                    </span>
                                </Row>
                                <p className="pt-2 text-xs text-muted-foreground">
                                    Percent part only; fixed per-operation fees
                                    are added on the daily report.
                                </p>
                            </dl>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Percent fees apply to sales only.
                            </p>
                        )}
                        {feeEntries.length > 0 && (
                            <>
                                <h3 className="mt-4 mb-1 text-xs font-semibold text-muted-foreground uppercase">
                                    Fees in the provider file
                                </h3>
                                <dl>
                                    {feeEntries.map(([key, value]) => (
                                        <Row key={key} label={key}>
                                            {formatMoney(
                                                value,
                                                operation.currency,
                                                4,
                                            )}
                                        </Row>
                                    ))}
                                </dl>
                            </>
                        )}
                    </Panel>

                    <Panel title="Source row">
                        {rawEntries.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No source row stored.
                            </p>
                        ) : (
                            <dl className="max-h-[28rem] overflow-y-auto">
                                {rawEntries.map(([key, value]) => (
                                    <Row key={key} label={key}>
                                        {value === null ||
                                        value === '' ? null : (
                                            <span className="font-mono text-xs">
                                                {String(value)}
                                            </span>
                                        )}
                                    </Row>
                                ))}
                            </dl>
                        )}
                    </Panel>
                </div>
            </PageBody>
        </>
    );
}

OperationShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Operations', href: admin.operations.index() },
        {
            title: props.operation.payment_id ?? `#${props.operation.id}`,
            href: admin.operations.show(props.operation.id),
        },
    ],
});
