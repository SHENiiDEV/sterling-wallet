import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Calculator } from 'lucide-react';
import { AffixInput, Field, FormSection } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatMoney } from '@/lib/money';
import admin from '@/routes/admin';
import type { CompanyRef, Merchant, Option } from '@/types';

type Props = {
    merchant: Merchant | null;
    companies: CompanyRef[];
    cryptoProviders: CompanyRef[];
    statuses: Option[];
    defaults: {
        rolling_reserve_percent: number;
        rolling_reserve_days: number;
        fee_fiat_to_crypto_percent: number;
    };
};

const NONE = 'none';
const NA = 'N/A';

type SchemeField =
    | 'fee_visa_eu_percent'
    | 'fee_visa_non_eu_percent'
    | 'fee_mastercard_eu_percent'
    | 'fee_mastercard_non_eu_percent';

/** "N/A" = the scheme is not offered; a new merchant starts empty. */
const schemeRate = (merchant: Merchant | null, field: SchemeField) =>
    merchant ? (merchant[field] === null ? NA : str(merchant[field])) : '';

const str = (value: string | number | null | undefined, fallback = '') =>
    value === null || value === undefined ? fallback : String(Number(value));

export default function MerchantForm({
    merchant,
    companies,
    cryptoProviders,
    statuses,
    defaults,
}: Props) {
    const form = useForm({
        name: merchant?.name ?? '',
        website: merchant?.website ?? '',
        company_id: merchant?.company_id ? String(merchant.company_id) : '',
        status: merchant?.status ?? 'onboarding',
        is_test: merchant?.is_test ?? false,
        crypto_provider_id: merchant?.crypto_provider_id
            ? String(merchant.crypto_provider_id)
            : '',
        fee_visa_eu_percent: schemeRate(merchant, 'fee_visa_eu_percent'),
        fee_visa_non_eu_percent: schemeRate(
            merchant,
            'fee_visa_non_eu_percent',
        ),
        fee_mastercard_eu_percent: schemeRate(
            merchant,
            'fee_mastercard_eu_percent',
        ),
        fee_mastercard_non_eu_percent: schemeRate(
            merchant,
            'fee_mastercard_non_eu_percent',
        ),
        fee_success_fixed: str(merchant?.fee_success_fixed, '0'),
        fee_decline_fixed: str(merchant?.fee_decline_fixed, '0'),
        fee_refund_fixed: str(merchant?.fee_refund_fixed, '0'),
        fee_chargeback_fixed: str(merchant?.fee_chargeback_fixed, '0'),
        fee_collab_fixed: str(merchant?.fee_collab_fixed),
        fee_wallet_percent: str(merchant?.fee_wallet_percent, '0'),
        fee_settlement_fx_percent: str(
            merchant?.fee_settlement_fx_percent,
            '0',
        ),
        fee_settlement_fixed: str(merchant?.fee_settlement_fixed, '0'),
        min_settlement_amount: str(merchant?.min_settlement_amount),
        rolling_reserve_cap: str(merchant?.rolling_reserve_cap),
        settlement_terms: merchant?.settlement_terms ?? '',
        fee_fiat_to_crypto_percent: str(
            merchant?.fee_fiat_to_crypto_percent,
            String(defaults.fee_fiat_to_crypto_percent),
        ),
        rolling_reserve_percent: str(
            merchant?.rolling_reserve_percent,
            String(defaults.rolling_reserve_percent),
        ),
        rolling_reserve_days: String(
            merchant?.rolling_reserve_days ?? defaults.rolling_reserve_days,
        ),
        invoice_email: merchant?.invoice_email ?? '',
        mcc: merchant?.mcc ?? '',
        onboarding_status: merchant?.onboarding_status ?? '',
        notes: merchant?.notes ?? '',
    });
    const { data, setData, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (merchant) {
            form.put(admin.merchants.update.url(merchant.public_id));
        } else {
            form.post(admin.merchants.store.url());
        }
    };

    const input = (
        key: keyof typeof data,
        label: string,
        suffix: string,
        hint?: string,
    ) => (
        <Field label={label} htmlFor={key} error={errors[key]} hint={hint}>
            <AffixInput
                id={key}
                suffix={suffix}
                value={data[key] as string}
                onChange={(e) => setData(key, e.target.value)}
            />
        </Field>
    );

    const rate = (key: SchemeField, label: string) => {
        const notOffered = data[key] === NA;

        return (
            <Field
                label={label}
                htmlFor={key}
                error={errors[key]}
                hint={notOffered ? 'Not offered' : undefined}
            >
                <div className="flex gap-2">
                    <div className="min-w-0 flex-1">
                        <AffixInput
                            id={key}
                            suffix={notOffered ? '' : '%'}
                            inputMode="decimal"
                            value={data[key]}
                            onChange={(e) => setData(key, e.target.value)}
                            disabled={notOffered}
                        />
                    </div>
                    <Button
                        type="button"
                        variant={notOffered ? 'default' : 'outline'}
                        className="shrink-0 px-3"
                        aria-pressed={notOffered}
                        onClick={() => setData(key, notOffered ? '' : NA)}
                    >
                        N/A
                    </Button>
                </div>
            </Field>
        );
    };

    const back = merchant
        ? admin.merchants.show(merchant.public_id)
        : admin.merchants.index();

    return (
        <>
            <Head title={merchant ? `Edit ${merchant.name}` : 'New merchant'} />
            <PageBody>
                <Link
                    href={back}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {merchant ? merchant.name : 'Merchants'}
                </Link>
                <PageHeader
                    title={merchant ? 'Edit merchant' : 'New merchant'}
                    description="One tariff for every MID. Percentages apply to volume; fixed fees are charged in each MID's own currency."
                />

                <form
                    onSubmit={submit}
                    className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start"
                >
                    <div className="grid gap-6">
                        <FormSection
                            title="General"
                            description="Who the merchant is and where statements go."
                        >
                            <Field
                                label="Merchant name"
                                htmlFor="name"
                                error={errors.name}
                                className="sm:col-span-2"
                            >
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                    autoFocus={!merchant}
                                />
                            </Field>
                            <Field label="Company" error={errors.company_id}>
                                <Select
                                    value={data.company_id || NONE}
                                    onValueChange={(value) =>
                                        setData(
                                            'company_id',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No company
                                        </SelectItem>
                                        {companies.map((company) => (
                                            <SelectItem
                                                key={company.id}
                                                value={String(company.id)}
                                            >
                                                {company.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="Status" error={errors.status}>
                                <Select
                                    value={data.status}
                                    onValueChange={(value) =>
                                        setData(
                                            'status',
                                            value as typeof data.status,
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((status) => (
                                            <SelectItem
                                                key={status.value}
                                                value={status.value}
                                            >
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label="Invoice email"
                                htmlFor="invoice_email"
                                error={errors.invoice_email}
                                hint="Daily statements are sent here"
                            >
                                <Input
                                    id="invoice_email"
                                    type="email"
                                    value={data.invoice_email}
                                    onChange={(e) =>
                                        setData('invoice_email', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Website"
                                htmlFor="website"
                                error={errors.website}
                            >
                                <Input
                                    id="website"
                                    value={data.website}
                                    onChange={(e) =>
                                        setData('website', e.target.value)
                                    }
                                    placeholder="shop.co.uk"
                                />
                            </Field>
                            <Field label="MCC" htmlFor="mcc" error={errors.mcc}>
                                <Input
                                    id="mcc"
                                    inputMode="numeric"
                                    maxLength={4}
                                    value={data.mcc}
                                    onChange={(e) =>
                                        setData('mcc', e.target.value)
                                    }
                                    className="font-mono"
                                />
                            </Field>
                            <Field
                                label="Onboarding stage"
                                htmlFor="onboarding_status"
                                error={errors.onboarding_status}
                                hint="Free text, e.g. PREPARE KYB"
                            >
                                <Input
                                    id="onboarding_status"
                                    value={data.onboarding_status}
                                    onChange={(e) =>
                                        setData(
                                            'onboarding_status',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <label className="flex items-start gap-3 self-end pb-2 text-sm">
                                <Checkbox
                                    checked={data.is_test}
                                    onCheckedChange={(checked) =>
                                        setData('is_test', checked === true)
                                    }
                                />
                                <span>
                                    Test merchant
                                    <span className="block text-xs text-muted-foreground">
                                        Excluded from bots, profit and
                                        dashboards
                                    </span>
                                </span>
                            </label>
                        </FormSection>

                        <FormSection
                            title="Card tariff"
                            description="Our fee on successful volume, by card brand and issuer region. Required once the merchant is active. N/A: the scheme is not offered."
                        >
                            {rate('fee_visa_eu_percent', 'Visa EU')}
                            {rate('fee_visa_non_eu_percent', 'Visa non-EU')}
                            {rate('fee_mastercard_eu_percent', 'Mastercard EU')}
                            {rate(
                                'fee_mastercard_non_eu_percent',
                                'Mastercard non-EU',
                            )}
                            {input(
                                'fee_wallet_percent',
                                'Apple Pay / Google Pay',
                                '%',
                                'Added to the card rate on wallet payments',
                            )}
                        </FormSection>

                        <FormSection
                            title="Per-operation fees"
                            description="Flat fees, charged in the currency of the MID the operation ran on."
                        >
                            {input(
                                'fee_success_fixed',
                                'Success fee',
                                'per op',
                            )}
                            {input(
                                'fee_decline_fixed',
                                'Decline fee',
                                'per op',
                            )}
                            {input('fee_refund_fixed', 'Refund fee', 'per op')}
                            {input(
                                'fee_chargeback_fixed',
                                'Chargeback fee',
                                'per op',
                            )}
                            {input(
                                'fee_collab_fixed',
                                'Collab',
                                'per op',
                                'Optional; leave empty if not agreed',
                            )}
                        </FormSection>

                        <FormSection
                            title="Reserve & payout"
                            description="Reserve is held per MID up to that MID's limit. Payouts are converted to USDC."
                        >
                            {input(
                                'rolling_reserve_percent',
                                'Rolling reserve',
                                '%',
                            )}
                            <Field
                                label="Reserve held for"
                                htmlFor="rolling_reserve_days"
                                error={errors.rolling_reserve_days}
                            >
                                <AffixInput
                                    id="rolling_reserve_days"
                                    suffix="days"
                                    inputMode="numeric"
                                    value={data.rolling_reserve_days}
                                    onChange={(e) =>
                                        setData(
                                            'rolling_reserve_days',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            {input(
                                'rolling_reserve_cap',
                                'Max reserve balance',
                                'cap',
                                'Once held, nothing more is reserved and the rest is paid out. Empty = no cap',
                            )}
                            {input(
                                'fee_fiat_to_crypto_percent',
                                'Conversion fee',
                                '%',
                                'Charged on the net payout',
                            )}
                            {input(
                                'fee_settlement_fx_percent',
                                'Settlement FX markup',
                                '%',
                                'On MIDs outside EUR, which the acquirer converts',
                            )}
                            {input(
                                'fee_settlement_fixed',
                                'Settlement charge',
                                'per settlement',
                                'Deducted from the payout when a settlement is created',
                            )}
                            {input(
                                'min_settlement_amount',
                                'Minimum settlement',
                                'min',
                                'For reference, not enforced',
                            )}
                            <Field
                                label="Settlement terms"
                                htmlFor="settlement_terms"
                                error={errors.settlement_terms}
                                hint="Printed on the daily report, e.g. Daily, T+3 Business Days"
                            >
                                <Input
                                    id="settlement_terms"
                                    value={data.settlement_terms}
                                    onChange={(e) =>
                                        setData(
                                            'settlement_terms',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Crypto provider"
                                error={errors.crypto_provider_id}
                            >
                                <Select
                                    value={data.crypto_provider_id || NONE}
                                    onValueChange={(value) =>
                                        setData(
                                            'crypto_provider_id',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            Not set
                                        </SelectItem>
                                        {cryptoProviders.map((provider) => (
                                            <SelectItem
                                                key={provider.id}
                                                value={String(provider.id)}
                                            >
                                                {provider.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </FormSection>

                        <FormSection title="Notes">
                            <Field
                                label="Internal notes"
                                htmlFor="notes"
                                error={errors.notes}
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    id="notes"
                                    rows={3}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                    </div>

                    <TariffPreview data={data} />

                    <div className="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur md:-mx-6 md:px-6 xl:col-span-2">
                        <Button variant="outline" asChild>
                            <Link href={back}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {merchant ? 'Save merchant' : 'Create merchant'}
                        </Button>
                    </div>
                </form>
            </PageBody>
        </>
    );
}

/**
 * Mirrors the daily report math for a sample day: 100 EU Visa sales
 * of 100.00 each, no refunds. Helps sanity-check a tariff while typing.
 */
function TariffPreview({
    data,
}: {
    data: {
        fee_visa_eu_percent: string;
        fee_mastercard_eu_percent: string;
        fee_success_fixed: string;
        rolling_reserve_percent: string;
        fee_fiat_to_crypto_percent: string;
    };
}) {
    const n = (value: string) => Number(value) || 0;
    const volume = 10000;
    const count = 100;
    const offered = [data.fee_visa_eu_percent, data.fee_mastercard_eu_percent]
        .filter((v) => v !== '' && v !== NA)
        .map(n);
    const rate =
        data.fee_visa_eu_percent && data.fee_visa_eu_percent !== NA
            ? n(data.fee_visa_eu_percent)
            : Math.max(0, ...offered);
    const fee = (volume * rate) / 100 + n(data.fee_success_fixed) * count;
    const base = volume - fee;
    const reserve = (base * n(data.rolling_reserve_percent)) / 100;
    const net = base - reserve;
    const conversion = (net * n(data.fee_fiat_to_crypto_percent)) / 100;
    const payout = net - conversion;

    const rows: [string, number, boolean][] = [
        ['Gross volume', volume, false],
        [`Merchant fee (${rate}% + fixed)`, -fee, true],
        [`Reserve (${n(data.rolling_reserve_percent)}%)`, -reserve, true],
        [
            `Conversion (${n(data.fee_fiat_to_crypto_percent)}%)`,
            -conversion,
            true,
        ],
    ];

    return (
        <aside className="rounded-xl border bg-card p-5 shadow-xs xl:sticky xl:top-4">
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <Calculator className="size-4 text-muted-foreground" />
                Sample day
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
                100 EU Visa sales × 100.00 in the MID currency
            </p>
            <dl className="mt-4 grid gap-2 text-sm">
                {rows.map(([label, value, muted]) => (
                    <div key={label} className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={
                                muted
                                    ? 'font-mono text-muted-foreground tabular-nums'
                                    : 'font-mono tabular-nums'
                            }
                        >
                            {formatMoney(value)}
                        </dd>
                    </div>
                ))}
            </dl>
            <div className="mt-4 flex items-end justify-between border-t pt-4">
                <span className="text-xs text-muted-foreground">
                    Paid to merchant
                </span>
                <span className="font-mono text-xl font-semibold tabular-nums">
                    {formatMoney(payout)}
                </span>
            </div>
            <p className="mt-3 text-xs text-muted-foreground">
                Our revenue: {formatMoney(fee + conversion)} before provider
                costs.
            </p>
        </aside>
    );
}

MerchantForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Merchants', href: admin.merchants.index() },
        props.merchant
            ? {
                  title: props.merchant.name,
                  href: admin.merchants.show(props.merchant.public_id),
              }
            : { title: 'New', href: admin.merchants.create() },
    ],
});
