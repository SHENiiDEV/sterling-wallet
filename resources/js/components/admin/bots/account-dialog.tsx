import { useForm } from '@inertiajs/react';
import { MidPicker } from '@/components/admin/bots/mid-picker';
import type { BotMid } from '@/components/admin/bots/mid-picker';
import { AffixInput, Field } from '@/components/admin/form';
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
export type { BotMid };

/** Mailboxes the Cardaq bot can read reports from (setting `mail_provider`). */
const MAIL_PROVIDERS = [
    { value: 'hostinger', label: 'Hostinger webmail' },
    { value: 'privateemail', label: 'PrivateEmail (Namecheap) webmail' },
];

const readSettings = (text: string): Record<string, unknown> => {
    try {
        const parsed = text.trim() === '' ? {} : JSON.parse(text);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? (parsed as Record<string, unknown>)
            : {};
    } catch {
        return {};
    }
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
        schedule_mode: account?.schedule_mode ?? 'auto',
        schedule_every_minutes: account?.schedule_every_minutes
            ? String(account.schedule_every_minutes)
            : '60',
        schedule_at: account?.schedule_at ?? '00:05',
    });
    const { data, setData, errors } = form;
    const isCardaq = data.connector === 'cardaq-export';
    const mailProvider =
        (readSettings(data.settings).mail_provider as string | undefined) ??
        'hostinger';
    const setMailProvider = (value: string) =>
        setData(
            'settings',
            JSON.stringify(
                { ...readSettings(data.settings), mail_provider: value },
                null,
                2,
            ),
        );
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
                        <Field
                            label="Provider"
                            error={errors.provider_id}
                            className="min-w-0"
                        >
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
                        <Field
                            label="Bot"
                            error={errors.connector}
                            className="min-w-0"
                        >
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
                        {isCardaq && (
                            <Field
                                label="Mailbox"
                                hint="Where Cardaq's report e-mails arrive. Use the matching login and password above"
                                className="sm:col-span-2"
                            >
                                <Select
                                    value={mailProvider}
                                    onValueChange={setMailProvider}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {MAIL_PROVIDERS.map((mail) => (
                                            <SelectItem
                                                key={mail.value}
                                                value={mail.value}
                                            >
                                                {mail.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        <Field
                            label="Settings (JSON)"
                            htmlFor="settings"
                            error={errors.settings}
                            hint='Cardaq: {"mail_provider": "privateemail", "search_query": "WORKING AGENT LTD", "search_template": "{query}, Processing period {date}"}. Any bot: {"proxy": "http://…"}'
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
                            label="Schedule"
                            error={
                                errors.schedule_mode ??
                                errors.schedule_every_minutes ??
                                errors.schedule_at
                            }
                            hint="When the scheduler starts this bot. A run only fetches what is still missing, so a run with nothing to fetch costs nothing."
                            className="sm:col-span-2"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Select
                                    value={data.schedule_mode}
                                    onValueChange={(v) =>
                                        setData('schedule_mode', v)
                                    }
                                >
                                    <SelectTrigger className="w-64">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="auto">
                                            Automatic (the bot's own rhythm)
                                        </SelectItem>
                                        <SelectItem value="interval">
                                            Every … minutes
                                        </SelectItem>
                                        <SelectItem value="daily">
                                            Once a day, from a time
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {data.schedule_mode === 'interval' && (
                                    <div className="w-36">
                                        <AffixInput
                                            suffix="min"
                                            inputMode="numeric"
                                            value={data.schedule_every_minutes}
                                            onChange={(e) =>
                                                setData(
                                                    'schedule_every_minutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                )}
                                {data.schedule_mode === 'daily' && (
                                    <Input
                                        type="time"
                                        className="w-32"
                                        value={data.schedule_at}
                                        onChange={(e) =>
                                            setData(
                                                'schedule_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </div>
                            {data.schedule_mode === 'daily' && (
                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    Report time zone. If the run does not
                                    succeed (for example the file is not there
                                    yet), it tries again every hour that day.
                                </p>
                            )}
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
                                <MidPicker
                                    mids={providerMids}
                                    value={data.mid_ids}
                                    onChange={(ids) => setData('mid_ids', ids)}
                                />
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
