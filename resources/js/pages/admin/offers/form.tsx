import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Download, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { AffixInput, Field, FormSection } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { StatusBadge } from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import admin from '@/routes/admin';
import type { Option } from '@/types';
import { offerColors } from '@/components/admin/offers';
import type { OfferItem } from '@/components/admin/offers';

type Offer = OfferItem & {
    contact_name: string | null;
    country: string | null;
    website: string | null;
    mcc: string | null;
    expected_monthly_volume: string | null;
    fee_visa_eu_percent: string | null;
    fee_visa_non_eu_percent: string | null;
    fee_mastercard_eu_percent: string | null;
    fee_mastercard_non_eu_percent: string | null;
    fee_acq_eu_percent: string;
    fee_acq_non_eu_percent: string;
    fee_success_fixed: string;
    fee_decline_fixed: string;
    fee_refund_fixed: string;
    fee_chargeback_fixed: string;
    fee_fiat_to_crypto_percent: string;
    setup_fee: string;
    rolling_reserve_percent: string;
    rolling_reserve_days: number;
    settlement_terms: string | null;
    terms: string | null;
    notes: string | null;
};

type Props = { offer: Offer | null; currencies: Option[] };

const num = (v: string | number | null | undefined, fallback = '') =>
    v === null || v === undefined ? fallback : String(Number(v));

export default function OfferForm({ offer, currencies }: Props) {
    const [deleting, setDeleting] = useState(false);
    const locked = offer?.status === 'accepted' || offer?.status === 'declined';
    const form = useForm({
        company_name: offer?.company_name ?? '',
        contact_name: offer?.contact_name ?? '',
        contact_email: offer?.contact_email ?? '',
        country: offer?.country ?? '',
        website: offer?.website ?? '',
        mcc: offer?.mcc ?? '',
        currencies: offer?.currencies ?? ['EUR'],
        expected_monthly_volume: num(offer?.expected_monthly_volume),
        fee_visa_eu_percent: num(offer?.fee_visa_eu_percent, '3'),
        fee_visa_non_eu_percent: num(offer?.fee_visa_non_eu_percent, '4'),
        fee_mastercard_eu_percent: num(offer?.fee_mastercard_eu_percent, '3'),
        fee_mastercard_non_eu_percent: num(
            offer?.fee_mastercard_non_eu_percent,
            '4',
        ),
        fee_acq_eu_percent: num(offer?.fee_acq_eu_percent, '3'),
        fee_acq_non_eu_percent: num(offer?.fee_acq_non_eu_percent, '4'),
        fee_success_fixed: num(offer?.fee_success_fixed, '0.2'),
        fee_decline_fixed: num(offer?.fee_decline_fixed, '0.1'),
        fee_refund_fixed: num(offer?.fee_refund_fixed, '1'),
        fee_chargeback_fixed: num(offer?.fee_chargeback_fixed, '25'),
        fee_fiat_to_crypto_percent: num(
            offer?.fee_fiat_to_crypto_percent,
            '0.4',
        ),
        setup_fee: num(offer?.setup_fee, '0'),
        rolling_reserve_percent: num(offer?.rolling_reserve_percent, '10'),
        rolling_reserve_days: String(offer?.rolling_reserve_days ?? 180),
        settlement_terms: offer?.settlement_terms ?? 'Daily, T+2',
        valid_until: offer?.valid_until ?? '',
        terms: offer?.terms ?? '',
        notes: offer?.notes ?? '',
    });
    const { data, setData, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (offer) {
            form.put(admin.offers.update.url(offer.id), {
                preserveScroll: true,
            });
        } else {
            form.post(admin.offers.store.url());
        }
    };

    const text = (
        key: keyof typeof data,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
    ) => (
        <Field label={label} htmlFor={key} error={errors[key]}>
            <Input
                id={key}
                value={data[key] as string}
                onChange={(e) => setData(key, e.target.value as never)}
                disabled={locked}
                {...props}
            />
        </Field>
    );
    const affix = (
        key: keyof typeof data,
        label: string,
        suffix: string,
        hint?: string,
    ) => (
        <Field label={label} htmlFor={key} error={errors[key]} hint={hint}>
            <AffixInput
                id={key}
                suffix={suffix}
                inputMode="decimal"
                value={data[key] as string}
                onChange={(e) => setData(key, e.target.value as never)}
                disabled={locked}
            />
        </Field>
    );
    const setStatus = (status: string) =>
        offer &&
        router.post(
            admin.offers.status.url(offer.id),
            { status },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={offer ? offer.number : 'New offer'} />
            <PageBody>
                <Link
                    href={admin.offers.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Commercial offers
                </Link>
                <PageHeader
                    title={
                        offer
                            ? `${offer.number} · ${offer.company_name}`
                            : 'New offer'
                    }
                    description={
                        offer ? (
                            <span className="inline-flex items-center gap-2">
                                <StatusBadge
                                    name={offer.status_label}
                                    color={offerColors[offer.status] ?? 'slate'}
                                />
                                {offer.merchant && (
                                    <Link
                                        href={admin.merchants.show(
                                            offer.merchant.public_id,
                                        )}
                                        className="hover:underline"
                                    >
                                        → merchant {offer.merchant.name}
                                    </Link>
                                )}
                            </span>
                        ) : (
                            'Fees mirror the merchant tariff, so accepting the offer creates the merchant with this pricing.'
                        )
                    }
                    actions={
                        offer && (
                            <>
                                <Button variant="outline" asChild>
                                    <a href={admin.offers.pdf.url(offer.id)}>
                                        <Download />
                                        PDF
                                    </a>
                                </Button>
                                {offer.status === 'draft' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setStatus('sent')}
                                    >
                                        <Send />
                                        Mark sent
                                    </Button>
                                )}
                                {!locked && (
                                    <>
                                        <Button
                                            onClick={() =>
                                                router.post(
                                                    admin.offers.accept.url(
                                                        offer.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <Check />
                                            Accept → merchant
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setStatus('declined')
                                            }
                                        >
                                            <X />
                                            Declined
                                        </Button>
                                    </>
                                )}
                                {offer.status === 'declined' && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setStatus('draft')}
                                    >
                                        Back to draft
                                    </Button>
                                )}
                                {offer.status === 'draft' && (
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="Delete"
                                        onClick={() => setDeleting(true)}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </>
                        )
                    }
                />
                <PageErrors keys={['offer']} />

                <form onSubmit={submit} className="grid gap-6">
                    <FormSection
                        title="Prospect"
                        description="Who the offer is for."
                    >
                        {text('company_name', 'Company', { autoFocus: !offer })}
                        {text('contact_name', 'Contact person')}
                        {text('contact_email', 'Contact e-mail', {
                            type: 'email',
                        })}
                        {text('country', 'Country (ISO)', {
                            maxLength: 2,
                            placeholder: 'LV',
                        })}
                        {text('website', 'Website')}
                        {text('mcc', 'MCC')}
                        <Field label="Currencies" error={errors.currencies}>
                            <div className="flex gap-4 pt-2">
                                {currencies.map((c) => (
                                    <label
                                        key={c.value}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={data.currencies.includes(
                                                c.value,
                                            )}
                                            disabled={locked}
                                            onCheckedChange={(on) =>
                                                setData(
                                                    'currencies',
                                                    on === true
                                                        ? [
                                                              ...data.currencies,
                                                              c.value,
                                                          ]
                                                        : data.currencies.filter(
                                                              (x) =>
                                                                  x !== c.value,
                                                          ),
                                                )
                                            }
                                        />
                                        {c.value}
                                    </label>
                                ))}
                            </div>
                        </Field>
                        {affix(
                            'expected_monthly_volume',
                            'Expected monthly volume',
                            'per month',
                        )}
                    </FormSection>

                    <FormSection
                        title="Card rates"
                        description="Percent of each sale. The fallback rate applies when the card brand is unknown."
                    >
                        {affix('fee_visa_eu_percent', 'Visa EU', '%')}
                        {affix('fee_visa_non_eu_percent', 'Visa non-EU', '%')}
                        {affix(
                            'fee_mastercard_eu_percent',
                            'Mastercard EU',
                            '%',
                        )}
                        {affix(
                            'fee_mastercard_non_eu_percent',
                            'Mastercard non-EU',
                            '%',
                        )}
                        {affix('fee_acq_eu_percent', 'Fallback EU', '%')}
                        {affix(
                            'fee_acq_non_eu_percent',
                            'Fallback non-EU',
                            '%',
                        )}
                    </FormSection>

                    <FormSection
                        title="Per operation"
                        description="Flat fees in the processing currency."
                    >
                        {affix(
                            'fee_success_fixed',
                            'Successful sale',
                            'per op',
                        )}
                        {affix('fee_decline_fixed', 'Decline', 'per op')}
                        {affix('fee_refund_fixed', 'Refund', 'per op')}
                        {affix('fee_chargeback_fixed', 'Chargeback', 'per op')}
                    </FormSection>

                    <FormSection
                        title="Settlement"
                        description="Payout in USDC, reserve and one-off fees."
                    >
                        {affix(
                            'fee_fiat_to_crypto_percent',
                            'Conversion to USDC',
                            '%',
                        )}
                        {affix(
                            'rolling_reserve_percent',
                            'Rolling reserve',
                            '%',
                        )}
                        {affix(
                            'rolling_reserve_days',
                            'Reserve held for',
                            'days',
                        )}
                        {affix('setup_fee', 'Setup fee', 'one-off')}
                        {text('settlement_terms', 'Settlement cycle')}
                        {text('valid_until', 'Valid until', { type: 'date' })}
                    </FormSection>

                    <FormSection title="Text">
                        <Field
                            label="Terms (printed on the PDF)"
                            htmlFor="terms"
                            error={errors.terms}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="terms"
                                rows={5}
                                value={data.terms}
                                onChange={(e) =>
                                    setData('terms', e.target.value)
                                }
                                disabled={locked}
                            />
                        </Field>
                        <Field
                            label="Internal notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                disabled={locked}
                            />
                        </Field>
                    </FormSection>

                    {!locked && (
                        <div className="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
                            <Button variant="outline" asChild>
                                <Link href={admin.offers.index()}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {offer ? 'Save offer' : 'Create offer'}
                            </Button>
                        </div>
                    )}
                </form>
            </PageBody>
            {offer && (
                <ConfirmDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={`Delete ${offer.number}?`}
                    description="Only drafts can be deleted."
                    onConfirm={() =>
                        router.delete(admin.offers.destroy.url(offer.id))
                    }
                />
            )}
        </>
    );
}

OfferForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Commercial offers', href: admin.offers.index() },
        {
            title: props.offer?.number ?? 'New',
            href: props.offer
                ? admin.offers.edit(props.offer.id)
                : admin.offers.create(),
        },
    ],
});
