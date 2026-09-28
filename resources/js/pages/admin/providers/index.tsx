import { Head, Link } from '@inertiajs/react';
import { Banknote, Coins, Network, Pencil, Plus } from 'lucide-react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { PageErrors } from '@/components/admin/page-errors';
import { Button } from '@/components/ui/button';
import { formatPercent } from '@/lib/money';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type { Option, Provider, ProviderType } from '@/types';

type Props = {
    providers: Provider[];
    types: Option[];
};

const typeMeta: Record<ProviderType, { icon: typeof Banknote; blurb: string }> =
    {
        bank: {
            icon: Banknote,
            blurb: 'Acquirers — cost applied to clearing (Cardaq) operations.',
        },
        gate: {
            icon: Network,
            blurb: 'Gateways — cost applied to gateway (Corefy) operations.',
        },
        crypto: {
            icon: Coins,
            blurb: 'Fiat → USDC conversion cost on merchant payouts.',
        },
    };

export default function ProvidersIndex({ providers, types }: Props) {
    return (
        <>
            <Head title="Providers" />
            <PageBody>
                <PageHeader
                    title="Providers"
                    description="What acquirers, gateways and crypto partners charge us. These costs drive margin in every daily report."
                    actions={
                        <Button asChild>
                            <Link href={admin.providers.create()}>
                                <Plus />
                                New provider
                            </Link>
                        </Button>
                    }
                />
                <PageErrors keys={['provider']} />

                {providers.length === 0 ? (
                    <div className="rounded-xl border bg-card shadow-xs">
                        <EmptyState
                            icon={Banknote}
                            title="No providers yet"
                            description="Add your acquirer (e.g. Cardaq), gateway (e.g. Corefy) and crypto partner before creating live MIDs."
                            action={
                                <Button asChild>
                                    <Link href={admin.providers.create()}>
                                        <Plus />
                                        New provider
                                    </Link>
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    types.map((type) => {
                        const group = providers.filter(
                            (p) => p.type === type.value,
                        );
                        const meta = typeMeta[type.value as ProviderType];

                        return (
                            <section key={type.value} className="grid gap-3">
                                <div className="flex items-center gap-2">
                                    <meta.icon className="size-4 text-muted-foreground" />
                                    <h2 className="text-sm font-semibold">
                                        {type.label}
                                    </h2>
                                    <span className="text-sm text-muted-foreground">
                                        · {meta.blurb}
                                    </span>
                                </div>
                                {group.length === 0 ? (
                                    <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                        None yet.
                                    </p>
                                ) : (
                                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                        {group.map((provider) => (
                                            <ProviderCard
                                                key={provider.id}
                                                provider={provider}
                                            />
                                        ))}
                                    </div>
                                )}
                            </section>
                        );
                    })
                )}
            </PageBody>
        </>
    );
}

function ProviderCard({ provider }: { provider: Provider }) {
    const rows: [string, string][] =
        provider.type === 'crypto'
            ? [['Conversion cost', formatPercent(provider.cost_crypto_percent)]]
            : [
                  [
                      'Visa EU / non-EU',
                      `${formatPercent(provider.cost_visa_eu_percent ?? provider.cost_acq_eu_percent)} / ${formatPercent(provider.cost_visa_non_eu_percent ?? provider.cost_acq_non_eu_percent)}`,
                  ],
                  [
                      'MC EU / non-EU',
                      `${formatPercent(provider.cost_mastercard_eu_percent ?? provider.cost_acq_eu_percent)} / ${formatPercent(provider.cost_mastercard_non_eu_percent ?? provider.cost_acq_non_eu_percent)}`,
                  ],
                  [
                      'Sale · refund · CHB',
                      `${Number(provider.cost_success_fixed)} · ${Number(provider.cost_refund_fixed)} · ${Number(provider.cost_chargeback_fixed)}`,
                  ],
              ];
    const usage =
        provider.type === 'crypto'
            ? `${provider.merchants_count ?? 0} merchants`
            : `${provider.mids_count ?? 0} MIDs`;

    return (
        <Link
            href={admin.providers.edit(provider.id)}
            className={cn(
                'group flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs transition hover:border-brand/40 hover:shadow-sm',
                !provider.is_active && 'opacity-60',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-sm font-semibold text-brand uppercase">
                        {provider.name.slice(0, 2)}
                    </span>
                    <div>
                        <p className="font-semibold">{provider.name}</p>
                        <p className="font-mono text-xs text-muted-foreground">
                            {provider.code}
                        </p>
                    </div>
                </div>
                <Pencil className="size-4 text-muted-foreground opacity-0 transition group-hover:opacity-100" />
            </div>
            <dl className="grid gap-1.5 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="font-mono tabular-nums">{value}</dd>
                    </div>
                ))}
            </dl>
            <div className="mt-auto flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                <span>{usage}</span>
                <span>
                    {provider.is_active ? (
                        <span className="text-success">Active</span>
                    ) : (
                        'Inactive'
                    )}
                </span>
            </div>
        </Link>
    );
}

ProvidersIndex.layout = {
    breadcrumbs: [{ title: 'Providers', href: admin.providers.index() }],
};
