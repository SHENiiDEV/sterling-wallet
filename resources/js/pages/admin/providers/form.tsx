import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/admin/confirm-dialog';
import { AffixInput, Field, FormSection } from '@/components/admin/form';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
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
import admin from '@/routes/admin';
import type { MatchingRules, Option, Provider } from '@/types';

type Props = {
    provider: Provider | null;
    types: Option[];
    reportFormats: Option[];
    connectors: Option[];
    defaultMatching: MatchingRules;
    defaultTimezone: string;
};

const NONE = '__none';

const str = (value: string | number | null | undefined, fallback = '') =>
    value === null || value === undefined ? fallback : String(Number(value));

export default function ProviderForm({
    provider,
    types,
    reportFormats,
    connectors,
    defaultMatching,
    defaultTimezone,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        name: provider?.name ?? '',
        code: provider?.code ?? '',
        type: provider?.type ?? 'bank',
        is_active: provider?.is_active ?? true,
        cost_visa_eu_percent: str(provider?.cost_visa_eu_percent),
        cost_visa_non_eu_percent: str(provider?.cost_visa_non_eu_percent),
        cost_mastercard_eu_percent: str(provider?.cost_mastercard_eu_percent),
        cost_mastercard_non_eu_percent: str(
            provider?.cost_mastercard_non_eu_percent,
        ),
        cost_acq_eu_percent: str(provider?.cost_acq_eu_percent, '0'),
        cost_acq_non_eu_percent: str(provider?.cost_acq_non_eu_percent, '0'),
        cost_success_fixed: str(provider?.cost_success_fixed, '0'),
        cost_decline_fixed: str(provider?.cost_decline_fixed, '0'),
        cost_refund_fixed: str(provider?.cost_refund_fixed, '0'),
        cost_chargeback_fixed: str(provider?.cost_chargeback_fixed, '0'),
        cost_crypto_percent: str(provider?.cost_crypto_percent, '0'),
        cost_wallet_percent: str(provider?.cost_wallet_percent, '0'),
        cost_settlement_fx_percent: str(
            provider?.cost_settlement_fx_percent,
            '0',
        ),
        settlement_fee: str(provider?.settlement_fee, '0'),
        settlement_cycle: provider?.settlement_cycle ?? '',
        min_settlement: str(provider?.min_settlement, '0'),
        rolling_reserve_percent: str(provider?.rolling_reserve_percent, '0'),
        rolling_reserve_days: String(provider?.rolling_reserve_days ?? 180),
        rolling_reserve_cap: str(provider?.rolling_reserve_cap, '0'),
        notes: provider?.notes ?? '',
        report_format: provider?.report_format ?? '',
        connector: provider?.connector ?? '',
        timezone: provider?.timezone ?? defaultTimezone,
        report_delay_days: String(provider?.report_delay_days ?? 1),
        matching: provider?.matching
            ? JSON.stringify(provider.matching, null, 2)
            : '',
    });
    const { data, setData, errors } = form;
    const isCrypto = data.type === 'crypto';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (provider) {
            form.put(admin.providers.update.url(provider.id));
        } else {
            form.post(admin.providers.store.url());
        }
    };

    const percent = (key: keyof typeof data, label: string, hint?: string) => (
        <Field label={label} htmlFor={key} error={errors[key]} hint={hint}>
            <AffixInput
                id={key}
                suffix="%"
                value={data[key] as string}
                onChange={(e) => setData(key, e.target.value)}
            />
        </Field>
    );

    const fixed = (key: keyof typeof data, label: string) => (
        <Field label={label} htmlFor={key} error={errors[key]}>
            <AffixInput
                id={key}
                suffix="per op"
                value={data[key] as string}
                onChange={(e) => setData(key, e.target.value)}
            />
        </Field>
    );

    return (
        <>
            <Head title={provider ? provider.name : 'New provider'} />
            <PageBody>
                <Link
                    href={admin.providers.index()}
                    className="-mb-2 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Providers
                </Link>
                <PageHeader
                    title={provider ? provider.name : 'New provider'}
                    description="All percentages are our cost. Fixed costs are charged in the currency of the MID they apply to."
                    actions={
                        provider && (
                            <Button
                                variant="outline"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 />
                                Delete
                            </Button>
                        )
                    }
                />
                <PageErrors keys={['provider']} />

                <form onSubmit={submit} className="grid gap-6">
                    <FormSection
                        title="General"
                        description="How this provider appears across the console."
                    >
                        <Field label="Name" htmlFor="name" error={errors.name}>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="Cardaq"
                                autoFocus={!provider}
                            />
                        </Field>
                        <Field
                            label="Code"
                            htmlFor="code"
                            error={errors.code}
                            hint="Short machine name used by bots, e.g. cardaq"
                        >
                            <Input
                                id="code"
                                value={data.code}
                                onChange={(e) =>
                                    setData(
                                        'code',
                                        e.target.value.toLowerCase(),
                                    )
                                }
                                className="font-mono"
                            />
                        </Field>
                        <Field label="Type" error={errors.type}>
                            <Select
                                value={data.type}
                                onValueChange={(value) =>
                                    setData('type', value as typeof data.type)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {types.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <label className="flex items-center gap-3 self-end pb-2 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            Active — can be assigned to new MIDs
                        </label>
                    </FormSection>

                    {!isCrypto && (
                        <FormSection
                            title="Reports & bots"
                            description="How the core reads this provider. Nothing in the core depends on the provider's name — only on these settings and its role on each MID."
                        >
                            <Field
                                label="Report format"
                                error={errors.report_format}
                                hint="Parser used for uploaded files"
                            >
                                <Select
                                    value={data.report_format || NONE}
                                    onValueChange={(value) =>
                                        setData(
                                            'report_format',
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
                                        {reportFormats.map((format) => (
                                            <SelectItem
                                                key={format.value}
                                                value={format.value}
                                            >
                                                {format.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label="Bot (connector)"
                                error={errors.connector}
                                hint="Which bot fetches its files"
                            >
                                <Select
                                    value={data.connector || NONE}
                                    onValueChange={(value) =>
                                        setData(
                                            'connector',
                                            value === NONE ? '' : value,
                                        )
                                    }
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            No bot — upload only
                                        </SelectItem>
                                        {connectors.map((connector) => (
                                            <SelectItem
                                                key={connector.value}
                                                value={connector.value}
                                            >
                                                {connector.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label="Time zone of operation times"
                                htmlFor="timezone"
                                error={errors.timezone}
                            >
                                <Input
                                    id="timezone"
                                    value={data.timezone}
                                    onChange={(e) =>
                                        setData('timezone', e.target.value)
                                    }
                                    placeholder="Europe/Riga"
                                    className="font-mono"
                                />
                            </Field>
                            <Field
                                label="Report delay"
                                htmlFor="report_delay_days"
                                error={errors.report_delay_days}
                                hint="Days after the period before the file is available"
                            >
                                <AffixInput
                                    id="report_delay_days"
                                    suffix="days"
                                    inputMode="numeric"
                                    value={data.report_delay_days}
                                    onChange={(e) =>
                                        setData(
                                            'report_delay_days',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Reconciliation rules (JSON)"
                                htmlFor="matching"
                                error={
                                    errors.matching ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('matching.'),
                                    )?.[1]
                                }
                                hint="Empty = defaults. Any key you set overrides the default."
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    id="matching"
                                    rows={6}
                                    className="font-mono text-xs"
                                    value={data.matching}
                                    placeholder={JSON.stringify(
                                        defaultMatching,
                                        null,
                                        2,
                                    )}
                                    onChange={(e) =>
                                        setData('matching', e.target.value)
                                    }
                                />
                            </Field>
                        </FormSection>
                    )}

                    {isCrypto ? (
                        <FormSection
                            title="Conversion cost"
                            description="Charged on the net payout volume converted to USDC."
                        >
                            {percent(
                                'cost_crypto_percent',
                                'Crypto conversion',
                            )}
                        </FormSection>
                    ) : (
                        <>
                            <FormSection
                                title="Card costs"
                                description="Leave a card rate empty to fall back to the generic EU / non-EU rate."
                            >
                                {percent('cost_visa_eu_percent', 'Visa EU')}
                                {percent(
                                    'cost_visa_non_eu_percent',
                                    'Visa non-EU',
                                )}
                                {percent(
                                    'cost_mastercard_eu_percent',
                                    'Mastercard EU',
                                )}
                                {percent(
                                    'cost_mastercard_non_eu_percent',
                                    'Mastercard non-EU',
                                )}
                                {percent(
                                    'cost_acq_eu_percent',
                                    'Fallback EU',
                                    'Used when the card brand is unknown',
                                )}
                                {percent(
                                    'cost_acq_non_eu_percent',
                                    'Fallback non-EU',
                                )}
                                {percent(
                                    'cost_wallet_percent',
                                    'Apple Pay / Google Pay',
                                    'Added to the card rate on wallet payments',
                                )}
                                {percent(
                                    'cost_settlement_fx_percent',
                                    'Settlement FX markup',
                                    'Charged when a MID is converted to the settlement currency (EUR)',
                                )}
                            </FormSection>
                            <FormSection
                                title="Per-operation costs"
                                description="Flat amount per operation, in the MID currency."
                            >
                                {fixed('cost_success_fixed', 'Successful sale')}
                                {fixed('cost_decline_fixed', 'Decline')}
                                {fixed('cost_refund_fixed', 'Refund')}
                                {fixed('cost_chargeback_fixed', 'Chargeback')}
                            </FormSection>
                            <FormSection
                                title="Settlement terms"
                                description="What the provider holds back from us. For reference and reconciliation."
                            >
                                <Field
                                    label="Settlement cycle"
                                    htmlFor="settlement_cycle"
                                    error={errors.settlement_cycle}
                                >
                                    <Input
                                        id="settlement_cycle"
                                        value={data.settlement_cycle}
                                        onChange={(e) =>
                                            setData(
                                                'settlement_cycle',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="T+2"
                                    />
                                </Field>
                                {fixed('settlement_fee', 'Settlement fee')}
                                <Field
                                    label="Minimum settlement"
                                    htmlFor="min_settlement"
                                    error={errors.min_settlement}
                                >
                                    <AffixInput
                                        id="min_settlement"
                                        value={data.min_settlement}
                                        onChange={(e) =>
                                            setData(
                                                'min_settlement',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                {percent(
                                    'rolling_reserve_percent',
                                    'Acquirer reserve',
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
                                <Field
                                    label="Reserve cap"
                                    htmlFor="rolling_reserve_cap"
                                    error={errors.rolling_reserve_cap}
                                >
                                    <AffixInput
                                        id="rolling_reserve_cap"
                                        value={data.rolling_reserve_cap}
                                        onChange={(e) =>
                                            setData(
                                                'rolling_reserve_cap',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </FormSection>
                        </>
                    )}

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

                    <div className="sticky bottom-0 -mx-4 flex justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
                        <Button variant="outline" asChild>
                            <Link href={admin.providers.index()}>Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {provider ? 'Save provider' : 'Create provider'}
                        </Button>
                    </div>
                </form>
            </PageBody>

            {provider && (
                <ConfirmDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    title={`Delete ${provider.name}?`}
                    description="Only possible while no MID or merchant uses it."
                    onConfirm={() =>
                        router.delete(admin.providers.destroy.url(provider.id))
                    }
                />
            )}
        </>
    );
}

ProviderForm.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Providers', href: admin.providers.index() },
        {
            title: props.provider?.name ?? 'New',
            href: props.provider
                ? admin.providers.edit(props.provider.id)
                : admin.providers.create(),
        },
    ],
});
