import { useForm } from '@inertiajs/react';
import { Check, Copy, Eye, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/admin/form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { postJson } from '@/lib/http';
import admin from '@/routes/admin';
import type { Option, Wallet } from '@/types';

export function WalletDialog({
    merchantId,
    wallet,
    types,
    canManageSeeds,
    onClose,
}: {
    merchantId: string;
    wallet: Wallet | null;
    types: Option[];
    canManageSeeds: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        type: wallet?.type ?? 'provider_inflow',
        label: wallet?.label ?? '',
        currency: wallet?.currency ?? 'USDT',
        network: wallet?.network ?? 'TRC20',
        address: wallet?.address ?? '',
        seed_phrase: '',
        notes: wallet?.notes ?? '',
        is_active: wallet?.is_active ?? true,
    });
    const { data, setData, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (wallet) {
            form.put(
                admin.merchants.wallets.update.url({
                    merchant: merchantId,
                    wallet: wallet.id,
                }),
                options,
            );
        } else {
            form.post(admin.merchants.wallets.store.url(merchantId), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {wallet ? 'Edit wallet' : 'Add wallet'}
                        </DialogTitle>
                        <DialogDescription>
                            Crypto addresses linked to this merchant.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Purpose" error={errors.type}>
                            <Select
                                value={data.type}
                                onValueChange={(value) =>
                                    setData('type', value)
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
                        <Field
                            label="Label"
                            htmlFor="w-label"
                            error={errors.label}
                        >
                            <Input
                                id="w-label"
                                value={data.label}
                                onChange={(e) =>
                                    setData('label', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Asset"
                            htmlFor="w-currency"
                            error={errors.currency}
                        >
                            <Input
                                id="w-currency"
                                value={data.currency}
                                onChange={(e) =>
                                    setData(
                                        'currency',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Network"
                            htmlFor="w-network"
                            error={errors.network}
                        >
                            <Input
                                id="w-network"
                                value={data.network}
                                onChange={(e) =>
                                    setData(
                                        'network',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Address"
                            htmlFor="w-address"
                            error={errors.address}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="w-address"
                                value={data.address}
                                onChange={(e) =>
                                    setData('address', e.target.value)
                                }
                                className="font-mono text-xs"
                            />
                        </Field>
                        {canManageSeeds && (
                            <Field
                                label="Seed phrase"
                                htmlFor="w-seed"
                                error={errors.seed_phrase}
                                hint={
                                    wallet?.has_seed
                                        ? 'Stored encrypted. Leave empty to keep the current one.'
                                        : 'Stored encrypted. Optional.'
                                }
                                className="sm:col-span-2"
                            >
                                <Textarea
                                    id="w-seed"
                                    rows={2}
                                    value={data.seed_phrase}
                                    onChange={(e) =>
                                        setData('seed_phrase', e.target.value)
                                    }
                                    className="font-mono text-xs"
                                    autoComplete="off"
                                />
                            </Field>
                        )}
                        <Field
                            label="Notes"
                            htmlFor="w-notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="w-notes"
                                rows={2}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                            />
                        </Field>
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            Active
                        </label>
                    </div>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {wallet ? 'Save wallet' : 'Add wallet'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function SeedRevealDialog({
    merchantId,
    wallet,
    onClose,
}: {
    merchantId: string;
    wallet: Wallet;
    onClose: () => void;
}) {
    const [seed, setSeed] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [copied, copy] = useClipboard();

    const reveal = async () => {
        setLoading(true);
        setFailed(false);

        try {
            const response = await postJson<{ seed_phrase: string | null }>(
                admin.merchants.wallets.seed.url({
                    merchant: merchantId,
                    wallet: wallet.id,
                }),
            );
            setSeed(response.seed_phrase ?? '');
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Seed phrase</DialogTitle>
                    <DialogDescription>
                        {wallet.label ?? wallet.type_label} · {wallet.currency}{' '}
                        {wallet.network}
                    </DialogDescription>
                </DialogHeader>

                {seed === null ? (
                    <div className="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                        <p>
                            Anyone with this phrase controls the funds. Viewing
                            it is recorded in the audit log with your name and
                            IP address.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <p className="rounded-lg bg-muted p-4 font-mono text-sm leading-relaxed break-words select-all">
                            {seed || 'No seed phrase stored.'}
                        </p>
                        {seed && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="justify-self-end"
                                onClick={() => copy(seed)}
                            >
                                {copied ? <Check /> : <Copy />}
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        )}
                    </div>
                )}
                {failed && (
                    <p className="text-sm text-destructive-foreground">
                        Could not load the seed phrase.
                    </p>
                )}

                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Close</Button>
                    </DialogClose>
                    {seed === null && (
                        <Button onClick={reveal} disabled={loading}>
                            {loading ? <Spinner /> : <Eye />}
                            Reveal
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
