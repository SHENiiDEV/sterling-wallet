import { useForm } from '@inertiajs/react';
import { AffixInput, Field } from '@/components/admin/form';
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
import type { Mid, Option, ProviderRef } from '@/types';

const NONE = 'none';

export function MidDialog({
    merchantId,
    mid,
    currencies,
    statuses,
    bankProviders,
    gateProviders,
    onClose,
}: {
    merchantId: string;
    mid: Mid | null;
    currencies: Option[];
    statuses: Option[];
    bankProviders: ProviderRef[];
    gateProviders: ProviderRef[];
    onClose: () => void;
}) {
    const form = useForm({
        mid: mid?.mid ?? '',
        provider_login: mid?.provider_login ?? '',
        currency: mid?.currency ?? 'EUR',
        label: mid?.label ?? '',
        status: mid?.status ?? 'active',
        bank_provider_id: mid?.bank_provider_id
            ? String(mid.bank_provider_id)
            : bankProviders.length === 1
              ? String(bankProviders[0].id)
              : '',
        gate_provider_id: mid?.gate_provider_id
            ? String(mid.gate_provider_id)
            : '',
        gate_mid: mid?.gate_mid ?? '',
        reports_start_date: mid?.reports_start_date ?? '',
        rolling_reserve_limit: mid
            ? String(Number(mid.rolling_reserve_limit))
            : '100000',
        processing_limit: mid?.processing_limit
            ? String(Number(mid.processing_limit))
            : '',
        notes: mid?.notes ?? '',
    });
    const { data, setData, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (mid) {
            form.put(
                admin.merchants.mids.update.url({
                    merchant: merchantId,
                    mid: mid.id,
                }),
                options,
            );
        } else {
            form.post(admin.merchants.mids.store.url(merchantId), options);
        }
    };

    const providerSelect = (
        key: 'bank_provider_id' | 'gate_provider_id',
        providers: ProviderRef[],
        emptyLabel: string,
    ) => (
        <Select
            value={data[key] || NONE}
            onValueChange={(value) => setData(key, value === NONE ? '' : value)}
        >
            <SelectTrigger className="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>{emptyLabel}</SelectItem>
                {providers.map((provider) => (
                    <SelectItem key={provider.id} value={String(provider.id)}>
                        {provider.name}
                        {provider.is_active === false && ' (inactive)'}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {mid ? `MID ${mid.mid}` : 'Add MID'}
                        </DialogTitle>
                        <DialogDescription>
                            One MID processes in one currency. Reports, reserve
                            and payouts are tracked per MID.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="MID" htmlFor="mid" error={errors.mid}>
                            <Input
                                id="mid"
                                value={data.mid}
                                onChange={(e) => setData('mid', e.target.value)}
                                className="font-mono"
                                autoFocus={!mid}
                            />
                        </Field>
                        <Field label="Currency" error={errors.currency}>
                            <Select
                                value={data.currency}
                                onValueChange={(value) =>
                                    setData(
                                        'currency',
                                        value as typeof data.currency,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((currency) => (
                                        <SelectItem
                                            key={currency.value}
                                            value={currency.value}
                                        >
                                            {currency.value} · {currency.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Provider login"
                            htmlFor="provider_login"
                            error={errors.provider_login}
                            hint="Alternative ID used in acquirer reports"
                        >
                            <Input
                                id="provider_login"
                                value={data.provider_login}
                                onChange={(e) =>
                                    setData('provider_login', e.target.value)
                                }
                                className="font-mono"
                            />
                        </Field>
                        <Field
                            label="Label"
                            htmlFor="label"
                            error={errors.label}
                        >
                            <Input
                                id="label"
                                value={data.label}
                                onChange={(e) =>
                                    setData('label', e.target.value)
                                }
                                placeholder="e.g. Main shop, UK traffic"
                            />
                        </Field>
                        <Field label="Acquirer" error={errors.bank_provider_id}>
                            {providerSelect(
                                'bank_provider_id',
                                bankProviders,
                                'Not set',
                            )}
                        </Field>
                        <Field label="Gateway" error={errors.gate_provider_id}>
                            {providerSelect(
                                'gate_provider_id',
                                gateProviders,
                                'None — clearing only',
                            )}
                        </Field>
                        {data.gate_provider_id && (
                            <Field
                                label="Gate MID"
                                htmlFor="gate_mid"
                                error={errors.gate_mid}
                                hint="How the gateway names this MID, e.g. a Corefy commerce account coma_…"
                            >
                                <Input
                                    id="gate_mid"
                                    value={data.gate_mid}
                                    onChange={(e) =>
                                        setData('gate_mid', e.target.value)
                                    }
                                    className="font-mono"
                                />
                            </Field>
                        )}
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
                            label="Expect reports from"
                            htmlFor="reports_start_date"
                            error={errors.reports_start_date}
                            hint="Bots will not look for earlier dates"
                        >
                            <Input
                                id="reports_start_date"
                                type="date"
                                value={data.reports_start_date}
                                onChange={(e) =>
                                    setData(
                                        'reports_start_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Reserve limit"
                            htmlFor="rolling_reserve_limit"
                            error={errors.rolling_reserve_limit}
                            hint="Stop holding once the reserve reaches this"
                        >
                            <AffixInput
                                id="rolling_reserve_limit"
                                suffix={data.currency}
                                value={data.rolling_reserve_limit}
                                onChange={(e) =>
                                    setData(
                                        'rolling_reserve_limit',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Processing limit"
                            htmlFor="processing_limit"
                            error={errors.processing_limit}
                            hint="Monthly, optional"
                        >
                            <AffixInput
                                id="processing_limit"
                                suffix={data.currency}
                                value={data.processing_limit}
                                onChange={(e) =>
                                    setData('processing_limit', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="mid-notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="mid-notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {mid ? 'Save MID' : 'Add MID'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
