import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Link2, Link2Off, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    CardLabel,
    OperationTypeBadge,
    RoleLabel,
} from '@/components/admin/operation-badges';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { Field } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type { Operation } from '@/types';

type Props = {
    operation: Operation;
    raw: Record<string, unknown>;
    fees: Record<string, string>;
    charges: {
        merchant_percent: string;
        merchant_fee: string;
        provider_percent: string | null;
        provider_cost: string | null;
    } | null;
    pair: Operation | null;
    candidates: Operation[];
    allAmounts: boolean;
    mid: {
        mid: string;
        currency: string;
        bank_provider: string | null;
        gate_provider: string | null;
    } | null;
    report: { id: number; report_date: string; status: string } | null;
};

type Edit = { by?: string; at?: string; reason?: string };

/** One readable line per value; the change history of an edited operation is listed, not dumped. */
function rawText(key: string, value: unknown): ReactNode {
    if (key === 'edits' && Array.isArray(value)) {
        return (
            <span className="flex flex-col items-end gap-1">
                {(value as Edit[]).map((edit, index) => (
                    <span key={index}>
                        {[
                            edit.at ? formatDateTime(edit.at) : null,
                            edit.by,
                            edit.reason,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                ))}
            </span>
        );
    }

    return typeof value === 'object'
        ? JSON.stringify(value)
        : String(value as string | number | boolean);
}

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
            <Row label="Reference">
                {op.reference && (
                    <span className="font-mono text-xs">{op.reference}</span>
                )}
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
            {op.collab && (
                <>
                    <Row label="Collab">
                        {op.collab.dispute ? (
                            <span className="font-medium text-rose-600">
                                Dispute: at or above{' '}
                                {Number(op.collab.threshold).toString()}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                Charged
                                {op.collab.threshold === null
                                    ? ''
                                    : `, below ${Number(op.collab.threshold)}`}
                            </span>
                        )}
                    </Row>
                    <Row label="Answer by">
                        {op.collab.deadline &&
                            formatDateTime(op.collab.deadline)}
                    </Row>
                    <Row label="Claim">{op.collab.claim_id}</Row>
                    <Row label="Original payment">
                        {[
                            op.collab.original_payment_id,
                            op.collab.original_date,
                            op.collab.original_amount,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </Row>
                </>
            )}
            {op.attempts !== null && op.attempts > 1 && (
                <Row label="Attempts">
                    {op.attempts}{' '}
                    <span className="text-muted-foreground">(cascaded)</span>
                </Row>
            )}
            <Row label="Customer">{op.customer_name}</Row>
            <Row label="Customer account">
                {op.customer_account && (
                    <span className="font-mono text-xs">
                        {op.customer_account}
                    </span>
                )}
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

/** Deleting asks for the payment ID typed back and a reason. */
function DeleteOperation({ operation }: { operation: Operation }) {
    const [open, setOpen] = useState(false);
    const expected = operation.payment_id || 'DELETE';
    const form = useForm({ confirm: '', reason: '' });
    const { data, setData, errors, processing } = form;

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Trash2 />
                Delete
            </Button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) form.reset();
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this operation?</DialogTitle>
                        <DialogDescription>
                            The operation is removed for good (the audit log
                            keeps a copy). A report that is already built does
                            not change: regenerate it afterwards. If the
                            provider file is uploaded again, the operation comes
                            back.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.delete(
                                admin.operations.destroy.url(operation.id),
                            );
                        }}
                    >
                        <Field
                            label={`Type ${expected} to confirm`}
                            htmlFor="confirm"
                            error={errors.confirm}
                        >
                            <Input
                                id="confirm"
                                value={data.confirm}
                                onChange={(e) =>
                                    setData('confirm', e.target.value)
                                }
                                className="font-mono"
                                autoComplete="off"
                            />
                        </Field>
                        <Field
                            label="Reason"
                            htmlFor="reason"
                            error={errors.reason}
                        >
                            <Textarea
                                id="reason"
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                rows={3}
                            />
                        </Field>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={
                                    processing ||
                                    data.confirm.trim() !== expected ||
                                    data.reason.trim() === ''
                                }
                            >
                                {processing && <Spinner />}
                                Delete operation
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function OperationShow({
    operation,
    raw,
    fees,
    charges,
    pair,
    candidates,
    allAmounts,
    mid,
    report,
}: Props) {
    const [unpairing, setUnpairing] = useState(false);
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
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={admin.operations.edit(operation.id)}
                                >
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                            <DeleteOperation operation={operation} />
                        </>
                    }
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
                                <span className="flex items-center gap-3">
                                    <Link
                                        href={admin.operations.show(pair.id)}
                                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                    >
                                        <Link2 className="size-3.5" />
                                        Open
                                    </Link>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-destructive"
                                        onClick={() => setUnpairing(true)}
                                    >
                                        <Link2Off className="size-3.5" />
                                        Unpair
                                    </button>
                                </span>
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
                        {!pair && mid?.gate_provider && (
                            <div className="grid gap-2 border-t pt-3">
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-xs font-semibold text-muted-foreground uppercase">
                                        Pair by hand
                                    </h3>
                                    <button
                                        type="button"
                                        className="text-xs text-primary hover:underline"
                                        onClick={() =>
                                            router.get(
                                                admin.operations.show.url(
                                                    operation.id,
                                                ),
                                                allAmounts ? {} : { all: 1 },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {allAmounts
                                            ? 'Same amount only'
                                            : 'Show other amounts'}
                                    </button>
                                </div>
                                {candidates.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No unpaired{' '}
                                        {operation.role === 'bank'
                                            ? 'gateway'
                                            : 'acquirer'}{' '}
                                        row
                                        {allAmounts
                                            ? ''
                                            : ' with this amount'}{' '}
                                        within five days.
                                    </p>
                                ) : (
                                    <ul className="grid gap-1.5">
                                        {candidates.map((c) => (
                                            <li
                                                key={c.id}
                                                className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2 text-sm"
                                            >
                                                <span className="font-mono text-xs">
                                                    {c.payment_id}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {c.transaction_at
                                                        ? formatDateTime(
                                                              c.transaction_at,
                                                          )
                                                        : '—'}
                                                </span>
                                                <span className="tabular-nums">
                                                    {formatMoney(
                                                        c.amount,
                                                        c.currency,
                                                    )}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {c.provider?.name}
                                                </span>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="ml-auto"
                                                    onClick={() =>
                                                        router.post(
                                                            admin.operations.pair.url(
                                                                operation.id,
                                                            ),
                                                            {
                                                                partner_id:
                                                                    c.id,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Pair
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
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
                                {charges.provider_cost !== null && (
                                    <Row label="Provider cost">
                                        {formatMoney(
                                            charges.provider_cost,
                                            operation.currency,
                                        )}{' '}
                                        <span className="text-muted-foreground">
                                            ({charges.provider_percent}%)
                                        </span>
                                    </Row>
                                )}
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
                                                {rawText(key, value)}
                                            </span>
                                        )}
                                    </Row>
                                ))}
                            </dl>
                        )}
                    </Panel>
                </div>
            </PageBody>
            <ConfirmDialog
                open={unpairing}
                onOpenChange={setUnpairing}
                title="Remove this pair?"
                description="Both rows become unpaired again; reconciliation may pair them anew, so pair them by hand to a different row if needed."
                onConfirm={() =>
                    router.delete(admin.operations.unpair.url(operation.id), {
                        preserveScroll: true,
                        onSuccess: () => setUnpairing(false),
                    })
                }
            />
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
