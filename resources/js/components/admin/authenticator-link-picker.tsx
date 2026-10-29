import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type LinkTarget = {
    id: number;
    name: string;
    parent_id?: number | null;
    hint?: string | null;
};
export type LinkTargets = Record<string, LinkTarget[]>;
export type Link = { type: string; id: number };

const TYPE_LABELS: Record<string, string> = {
    client: 'Client',
    company: 'Company',
    merchant: 'Merchant',
    provider: 'Provider',
};

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'client', label: 'Clients' },
    { value: 'company', label: 'Companies' },
    { value: 'merchant', label: 'Merchants' },
    { value: 'provider', label: 'Providers' },
] as const;

type Row = {
    key: string;
    type: string;
    id: number;
    name: string;
    hint: string | null;
    depth: number;
    ancestors: string[];
    parentKeys: string[];
};

const keyOf = (type: string, id: number) => `${type}:${id}`;

/** Clients with their companies and the merchants of each, then what hangs under no client, then providers. */
function buildRows(targets: LinkTargets): Row[] {
    const rows: Row[] = [];
    const companies = targets.company ?? [];
    const merchants = targets.merchant ?? [];

    const merchantRows = (
        company: LinkTarget | null,
        depth: number,
        ancestors: string[],
        parentKeys: string[],
    ) => {
        for (const m of merchants.filter(
            (m) => (m.parent_id ?? null) === (company?.id ?? null),
        )) {
            rows.push({
                key: keyOf('merchant', m.id),
                type: 'merchant',
                id: m.id,
                name: m.name,
                hint: m.hint ?? null,
                depth,
                ancestors,
                parentKeys,
            });
        }
    };

    const companyRows = (
        clientId: number | null,
        depth: number,
        ancestors: string[],
        parentKeys: string[],
    ) => {
        for (const c of companies.filter(
            (c) => (c.parent_id ?? null) === clientId,
        )) {
            rows.push({
                key: keyOf('company', c.id),
                type: 'company',
                id: c.id,
                name: c.name,
                hint: null,
                depth,
                ancestors,
                parentKeys,
            });
            merchantRows(
                c,
                depth + 1,
                [...ancestors, c.name],
                [...parentKeys, keyOf('company', c.id)],
            );
        }
    };

    for (const client of targets.client ?? []) {
        rows.push({
            key: keyOf('client', client.id),
            type: 'client',
            id: client.id,
            name: client.name,
            hint: null,
            depth: 0,
            ancestors: [],
            parentKeys: [],
        });
        companyRows(client.id, 1, [client.name], [keyOf('client', client.id)]);
    }

    // Companies without a client, and merchants without a company.
    companyRows(null, 0, [], []);
    merchantRows(null, 0, [], []);

    for (const p of targets.provider ?? []) {
        rows.push({
            key: keyOf('provider', p.id),
            type: 'provider',
            id: p.id,
            name: p.name,
            hint: null,
            depth: 0,
            ancestors: [],
            parentKeys: [],
        });
    }

    return rows;
}

/**
 * Pick what a code is shown with: type to search, and every client lists its companies and their merchants
 * beneath it, so "which companies does this client have" is answered right in the list.
 */
export function AuthenticatorLinkPicker({
    targets,
    value,
    onChange,
}: {
    targets: LinkTargets;
    value: Link[];
    onChange: (links: Link[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] =
        useState<(typeof FILTERS)[number]['value']>('all');
    const rows = useMemo(() => buildRows(targets), [targets]);

    const needle = query.trim().toLowerCase();
    const visible = useMemo(() => {
        const matches = (row: Row) =>
            (filter === 'all' || row.type === filter) &&
            (needle === '' ||
                [row.name, row.hint ?? '', ...row.ancestors].some((text) =>
                    text.toLowerCase().includes(needle),
                ));
        const hit = rows.filter(matches);
        // A hit keeps its client and company visible, so it is clear where it sits.
        const keep = new Set(hit.flatMap((r) => [r.key, ...r.parentKeys]));

        return rows.filter((r) => keep.has(r.key));
    }, [rows, needle, filter]);

    const selected = new Set(value.map((l) => keyOf(l.type, l.id)));
    const toggle = (row: Row) =>
        onChange(
            selected.has(row.key)
                ? value.filter((l) => keyOf(l.type, l.id) !== row.key)
                : [...value, { type: row.type, id: row.id }],
        );

    return (
        <div className="grid min-w-0 gap-2 rounded-md border p-2.5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative min-w-0 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search a client, company, merchant or provider…"
                        className="pl-8"
                    />
                </div>
                <div className="flex flex-wrap gap-1">
                    {FILTERS.map((f) => (
                        <button
                            key={f.value}
                            type="button"
                            onClick={() => setFilter(f.value)}
                            className={cn(
                                'rounded-md border px-2 py-1 text-xs',
                                filter === f.value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>
            </div>
            <div className="max-h-60 overflow-y-auto rounded-md border bg-muted/20">
                {visible.length === 0 ? (
                    <p className="p-3 text-sm text-muted-foreground">
                        Nothing matches.
                    </p>
                ) : (
                    visible.map((row) => (
                        <label
                            key={row.key}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 border-b px-2.5 py-1.5 text-sm last:border-0 hover:bg-accent/50',
                                row.type === 'client' &&
                                    'bg-muted/50 font-medium',
                            )}
                            style={{
                                paddingLeft: `${0.625 + row.depth * 1.25}rem`,
                            }}
                        >
                            <Checkbox
                                checked={selected.has(row.key)}
                                onCheckedChange={() => toggle(row)}
                            />
                            <span className="min-w-0 flex-1 truncate">
                                {row.name}
                                {row.hint && (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        {' '}
                                        · {row.hint}
                                    </span>
                                )}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {TYPE_LABELS[row.type]}
                            </span>
                        </label>
                    ))
                )}
            </div>
        </div>
    );
}
