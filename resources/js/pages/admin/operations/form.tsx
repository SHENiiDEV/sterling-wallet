import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import { Field, FormSection } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import admin from '@/routes/admin';
import type { Option } from '@/types';

type MidOption = {
    id: number;
    mid: string;
    currency: string;
    merchant: string | null;
    providers: { id: number; name: string; role: string }[];
};

type Props = {
    operation: Record<string, string | number | boolean> | null;
    mids: MidOption[];
    types: Option[];
    currencies: Option[];
};

const FEES = [
    'eu_fee',
    'non_eu_fee',
    'ic_fee',
    'ic_interchange',
    'ic_scheme_fee',
    'approve_fee',
    'decline_fee',
    'refund_fee',
] as const;

export default function OperationForm({
    operation,
    mids,
    types,
    currencies,
}: Props) {
    const editing = operation !== null;
    const initial = (key: string, fallback = '') =>
        operation && operation[key] !== undefined && operation[key] !== null
            ? String(operation[key])
            : fallback;
    const form = useForm({
        merchant_mid_id: initial('merchant_mid_id', ''),
        provider_id: initial('provider_id', ''),
        payment_id: initial('payment_id', ''),
        sp_id: initial('sp_id', ''),
        arn: initial('arn', ''),
        rrn: initial('rrn', ''),
        approval_code: initial('approval_code', ''),
        card_mask: initial('card_mask', ''),
        card_bin: initial('card_bin', ''),
        card_last4: initial('card_last4', ''),
        customer_email: initial('customer_email', ''),
        customer_name: initial('customer_name', ''),
        customer_account: initial('customer_account', ''),
        ips: initial('ips', ''),
        wallet: initial('wallet', ''),
        region: initial('region', ''),
        issuer_country: initial('issuer_country', ''),
        issuer_name: initial('issuer_name', ''),
        trn_type: initial('trn_type', ''),
        operation_type: initial('operation_type', 'sale'),
        processing_code: initial('processing_code', ''),
        resolution: initial('resolution', ''),
        report_date: initial('report_date', ''),
        transaction_at: initial('transaction_at', ''),
        processing_at: initial('processing_at', ''),
        amount: initial('amount', ''),
        currency: initial('currency', 'EUR'),
        eu_fee: initial('eu_fee', ''),
        non_eu_fee: initial('non_eu_fee', ''),
        ic_fee: initial('ic_fee', ''),
        ic_interchange: initial('ic_interchange', ''),
        ic_scheme_fee: initial('ic_scheme_fee', ''),
        approve_fee: initial('approve_fee', ''),
        decline_fee: initial('decline_fee', ''),
        refund_fee: initial('refund_fee', ''),
        reason: initial('reason', ''),
    });
    const { data, setData, errors, processing } = form;
    const mid = mids.find((m) => String(m.id) === data.merchant_mid_id);

    const text = (
        name: keyof typeof data,
        label: string,
        props: { type?: string; hint?: string; mono?: boolean } = {},
    ) => (
        <Field
            label={label}
            htmlFor={name}
            error={errors[name]}
            hint={props.hint}
        >
            <Input
                id={name}
                type={props.type}
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
                className={props.mono ? 'font-mono' : undefined}
            />
        </Field>
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onError: () => window.scrollTo({ top: 0, behavior: 'smooth' }),
        };

        if (editing) {
            form.put(
                admin.operations.update.url(Number(operation.id)),
                options,
            );
        } else {
            form.post(admin.operations.store.url(), options);
        }
    };

    return (
        <>
            <Head title={editing ? 'Edit operation' : 'Add operation'} />
            <PageBody>
                <Link
                    href={admin.operations.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Operations
                </Link>
                <PageHeader
                    title={editing ? 'Edit operation' : 'Add operation'}
                    description={
                        editing
                            ? operation.from_file
                                ? 'This row came from a provider file: uploading that file again overwrites your changes. Every change is recorded in the audit log.'
                                : 'Every change is recorded in the audit log.'
                            : 'Enter an operation by hand, e.g. one that was processed on another terminal. It is marked as manual and recorded in the audit log.'
                    }
                />

                {Object.keys(errors).length > 0 && (
                    <div className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive-foreground">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div className="grid gap-1">
                            <p className="font-medium">
                                Not saved: fix the fields below.
                            </p>
                            {Object.entries(errors).map(([field, message]) => (
                                <p key={field}>{message}</p>
                            ))}
                        </div>
                    </div>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6">
                    <FormSection
                        title="Where it belongs"
                        description="The MID decides the merchant. The provider is the acquirer or the gateway of that MID."
                    >
                        <Field
                            label="MID"
                            error={errors.merchant_mid_id}
                            className="sm:col-span-2"
                        >
                            <Select
                                value={data.merchant_mid_id}
                                onValueChange={(value) => {
                                    const next = mids.find(
                                        (m) => String(m.id) === value,
                                    );
                                    setData((d) => ({
                                        ...d,
                                        merchant_mid_id: value,
                                        currency: next?.currency ?? d.currency,
                                        provider_id:
                                            next?.providers.length === 1
                                                ? String(next.providers[0].id)
                                                : '',
                                    }));
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose a MID" />
                                </SelectTrigger>
                                <SelectContent>
                                    {mids.map((m) => (
                                        <SelectItem
                                            key={m.id}
                                            value={String(m.id)}
                                        >
                                            {m.mid} · {m.merchant ?? '—'} ·{' '}
                                            {m.currency}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Provider"
                            error={errors.provider_id}
                            hint="Acquirer = clearing row, gateway = payment row."
                        >
                            <Select
                                value={data.provider_id}
                                onValueChange={(value) =>
                                    setData('provider_id', value)
                                }
                                disabled={!mid}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose a provider" />
                                </SelectTrigger>
                                <SelectContent>
                                    {(mid?.providers ?? []).map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                        >
                                            {p.name} ({p.role})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Type" error={errors.operation_type}>
                            <Select
                                value={data.operation_type}
                                onValueChange={(value) =>
                                    setData('operation_type', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {types.map((t) => (
                                        <SelectItem
                                            key={t.value}
                                            value={t.value}
                                        >
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Amount and time"
                        description="Report date is the day of the daily report that should contain it."
                    >
                        {text('amount', 'Amount', {
                            hint: 'Negative for refunds.',
                        })}
                        <Field label="Currency" error={errors.currency}>
                            <Select
                                value={data.currency}
                                onValueChange={(value) =>
                                    setData('currency', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((c) => (
                                        <SelectItem
                                            key={c.value}
                                            value={c.value}
                                        >
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        {text('report_date', 'Report date', { type: 'date' })}
                        {text('transaction_at', 'Transaction time (UTC)', {
                            type: 'datetime-local',
                        })}
                        {text('processing_at', 'Processing time (UTC)', {
                            type: 'datetime-local',
                        })}
                    </FormSection>

                    <FormSection title="Identifiers">
                        {text('payment_id', 'Payment ID', {
                            mono: true,
                            hint: 'Required: it also prevents entering the same operation twice.',
                        })}
                        {text('sp_id', 'Pair ID (sp_id)', { mono: true })}
                        {text('arn', 'ARN', { mono: true })}
                        {text('rrn', 'RRN', { mono: true })}
                        {text('approval_code', 'Approval code', { mono: true })}
                        {text('trn_type', 'trn_type')}
                        {text('processing_code', 'Processing code')}
                        {text('resolution', 'Resolution / status')}
                    </FormSection>

                    <FormSection title="Card and customer">
                        {text('card_bin', 'Card BIN', { mono: true })}
                        {text('card_last4', 'Card last 4', { mono: true })}
                        {text('card_mask', 'Card mask', { mono: true })}
                        {text('customer_name', 'Customer name', {
                            hint: 'Sender / cardholder, as the provider shows it',
                        })}
                        {text('customer_account', 'Customer account (IBAN)', {
                            mono: true,
                        })}
                        {text('customer_email', 'Customer e-mail', {
                            type: 'email',
                        })}
                        {text('ips', 'Scheme (ips)', {
                            hint: 'e.g. visa, mastercard',
                        })}
                        {text('wallet', 'Wallet', {
                            hint: 'apple_pay / google_pay, if used',
                        })}
                        <Field label="Region" error={errors.region}>
                            <Select
                                value={data.region || 'none'}
                                onValueChange={(value) =>
                                    setData(
                                        'region',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Not set
                                    </SelectItem>
                                    <SelectItem value="eu">EU</SelectItem>
                                    <SelectItem value="non_eu">
                                        Non-EU
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {text('issuer_country', 'Issuer country', {
                            hint: '2 letters, e.g. DE',
                        })}
                        {text('issuer_name', 'Issuer name')}
                    </FormSection>

                    <FormSection
                        title="Provider fees"
                        description="Only what the provider file would show. Leave empty if unknown."
                    >
                        {FEES.map((fee) => text(fee, fee, { mono: true }))}
                    </FormSection>

                    <FormSection title="Reason">
                        <Field
                            label={
                                editing
                                    ? 'Why is it changed?'
                                    : 'Why is it added by hand?'
                            }
                            error={errors.reason}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                rows={3}
                            />
                        </Field>
                    </FormSection>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" asChild>
                            <Link href={admin.operations.index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {editing ? 'Save changes' : 'Add operation'}
                        </Button>
                    </div>
                </form>
            </PageBody>
        </>
    );
}

OperationForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Operations', href: admin.operations.index() },
        props.operation
            ? {
                  title: 'Edit operation',
                  href: admin.operations.edit(Number(props.operation.id)),
              }
            : { title: 'Add operation', href: admin.operations.create() },
    ],
});
