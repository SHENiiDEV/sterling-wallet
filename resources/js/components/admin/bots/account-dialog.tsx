import { useForm } from '@inertiajs/react';
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
import admin from '@/routes/admin';
import type { IntegrationAccount, Option } from '@/types';

export type BotProvider = {
    id: number;
    name: string;
    connector: string | null;
};
export type BotMid = {
    id: number;
    mid: string;
    merchant: string;
    provider_ids: number[];
};

export function AccountDialog({
    account,
    providers,
    connectors,
    mids,
    onClose,
}: {
    account: IntegrationAccount | null;
    providers: BotProvider[];
    connectors: Option[];
    mids: BotMid[];
    onClose: () => void;
}) {
    const firstProvider = providers.find((p) => p.connector) ?? providers[0];
    const form = useForm({
        provider_id: String(account?.provider.id ?? firstProvider?.id ?? ''),
        connector:
            account?.connector ??
            firstProvider?.connector ??
            connectors[0]?.value ??
            '',
        name: account?.name ?? '',
        login_url: account?.login_url ?? '',
        username: '',
        password: '',
        totp_secret: '',
        clear_totp_secret: false,
        settings: account?.settings
            ? JSON.stringify(account.settings, null, 2)
            : '',
        mid_ids: (account?.mid_ids ?? []).map(String),
        is_active: account?.is_active ?? true,
    });
    const { data, setData, errors } = form;
    const providerMids = mids.filter((mid) =>
        mid.provider_ids.includes(Number(data.provider_id)),
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (account) {
            form.put(admin.bots.accounts.update.url(account.id), options);
        } else {
            form.post(admin.bots.accounts.store.url(), options);
        }
    };

    const secretHint = (has: boolean | undefined) =>
        account && has ? 'Stored. Leave empty to keep it.' : undefined;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {account ? account.name : 'Add bot account'}
                        </DialogTitle>
                        <DialogDescription>
                            A portal login a bot uses. Credentials are stored
                            encrypted and never shown again.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" htmlFor="name" error={errors.name}>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                placeholder="Corefy — main login"
                                autoFocus={!account}
                            />
                        </Field>
                        <Field label="Provider" error={errors.provider_id}>
                            <Select
                                value={data.provider_id}
                                onValueChange={(value) => {
                                    const provider = providers.find(
                                        (p) => String(p.id) === value,
                                    );
                                    setData((current) => ({
                                        ...current,
                                        provider_id: value,
                                        connector:
                                            provider?.connector ??
                                            current.connector,
                                        mid_ids: [],
                                    }));
                                }}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((provider) => (
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
                        <Field label="Bot" error={errors.connector}>
                            <Select
                                value={data.connector}
                                onValueChange={(value) =>
                                    setData('connector', value)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Choose…" />
                                </SelectTrigger>
                                <SelectContent>
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
                            label="Login URL"
                            htmlFor="login_url"
                            error={errors.login_url}
                            hint="Empty = the bot's default"
                        >
                            <Input
                                id="login_url"
                                value={data.login_url}
                                onChange={(e) =>
                                    setData('login_url', e.target.value)
                                }
                                placeholder="https://…"
                            />
                        </Field>
                        <Field
                            label="Login"
                            htmlFor="username"
                            error={errors.username}
                            hint={secretHint(account?.has_username)}
                        >
                            <Input
                                id="username"
                                value={data.username}
                                onChange={(e) =>
                                    setData('username', e.target.value)
                                }
                                autoComplete="off"
                            />
                        </Field>
                        <Field
                            label="Password"
                            htmlFor="password"
                            error={errors.password}
                            hint={secretHint(account?.has_password)}
                        >
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) =>
                                    setData('password', e.target.value)
                                }
                                autoComplete="new-password"
                            />
                        </Field>
                        <Field
                            label="2FA secret (TOTP)"
                            htmlFor="totp_secret"
                            error={errors.totp_secret}
                            hint={
                                secretHint(account?.has_totp) ??
                                'Base32 secret, only if the portal asks for a code'
                            }
                        >
                            <Input
                                id="totp_secret"
                                value={data.totp_secret}
                                onChange={(e) =>
                                    setData('totp_secret', e.target.value)
                                }
                                autoComplete="off"
                                className="font-mono"
                            />
                        </Field>
                        {account?.has_totp && (
                            <label className="flex items-center gap-3 self-end pb-2 text-sm">
                                <Checkbox
                                    checked={data.clear_totp_secret}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'clear_totp_secret',
                                            checked === true,
                                        )
                                    }
                                />
                                Remove stored 2FA secret
                            </label>
                        )}
                        <Field
                            label="Settings (JSON)"
                            htmlFor="settings"
                            error={errors.settings}
                            hint='e.g. {"search_query": "EXORAPAY FINANCE LTD"} for Cardaq, {"proxy": "http://…"}'
                            className="sm:col-span-2"
                        >
                            <Textarea
                                id="settings"
                                rows={3}
                                value={data.settings}
                                onChange={(e) =>
                                    setData('settings', e.target.value)
                                }
                                className="font-mono text-xs"
                            />
                        </Field>
                        <Field
                            label="MIDs this account covers"
                            error={errors.mid_ids}
                            hint="None ticked = every MID of this provider"
                            className="sm:col-span-2"
                        >
                            {providerMids.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No MID uses this provider yet.
                                </p>
                            ) : (
                                <div className="grid max-h-40 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2">
                                    {providerMids.map((mid) => {
                                        const checked = data.mid_ids.includes(
                                            String(mid.id),
                                        );

                                        return (
                                            <label
                                                key={mid.id}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    onCheckedChange={(on) =>
                                                        setData(
                                                            'mid_ids',
                                                            on === true
                                                                ? [
                                                                      ...data.mid_ids,
                                                                      String(
                                                                          mid.id,
                                                                      ),
                                                                  ]
                                                                : data.mid_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          String(
                                                                              mid.id,
                                                                          ),
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <span className="font-mono text-xs">
                                                    {mid.mid}
                                                </span>
                                                <span className="truncate text-muted-foreground">
                                                    {mid.merchant}
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                        </Field>
                        <label className="flex items-center gap-3 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            Active — the scheduler runs this account
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
                            {account ? 'Save account' : 'Add account'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
