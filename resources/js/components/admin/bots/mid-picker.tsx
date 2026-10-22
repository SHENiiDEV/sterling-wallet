import { Search } from 'lucide-react';
import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export type BotMid = {
    id: number;
    mid: string;
    merchant: string;
    company: string | null;
    client: string | null;
    status: 'active' | 'inactive' | 'review';
    provider_ids: number[];
};

const GROUPS = [
    { status: 'active', title: 'Active MIDs' },
    { status: 'inactive', title: 'Inactive MIDs' },
    { status: 'review', title: 'In review' },
] as const;

/**
 * MIDs a bot account covers: active ones first, inactive and in-review ones
 * in their own lists, searchable by MID, merchant, company or client.
 */
export function MidPicker({
    mids,
    value,
    onChange,
}: {
    mids: BotMid[];
    value: string[];
    onChange: (ids: string[]) => void;
}) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<'all' | BotMid['status']>('all');

    const needle = query.trim().toLowerCase();
    const matches = (mid: BotMid) =>
        needle === '' ||
        [mid.mid, mid.merchant, mid.company, mid.client].some((field) =>
            field?.toLowerCase().includes(needle),
        );
    const selected = new Set(value);
    const toggle = (ids: string[], on: boolean) =>
        onChange(
            on
                ? [...new Set([...value, ...ids])]
                : value.filter((id) => !ids.includes(id)),
        );

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search MID, merchant, company or client…"
                        className="h-8 pl-8"
                    />
                </div>
                <div className="flex flex-wrap gap-1">
                    {(
                        [
                            ['all', 'All'],
                            ...GROUPS.map((g) => [g.status, g.title]),
                        ] as [string, string][]
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setStatus(key as typeof status)}
                            className={cn(
                                'rounded-md border px-2 py-1 text-xs font-medium',
                                status === key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                            <span className="ml-1 opacity-70">
                                {
                                    mids.filter(
                                        (m) =>
                                            key === 'all' || m.status === key,
                                    ).length
                                }
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="max-h-72 overflow-y-auto">
                {GROUPS.filter((g) => status === 'all' || status === g.status)
                    .map((g) => ({
                        ...g,
                        list: mids.filter(
                            (m) => m.status === g.status && matches(m),
                        ),
                    }))
                    .filter((g) => g.list.length > 0)
                    .map((g) => {
                        const ids = g.list.map((m) => String(m.id));
                        const chosen = ids.filter((id) => selected.has(id));

                        return (
                            <section key={g.status} className="mb-3 last:mb-0">
                                <label className="sticky top-0 z-10 flex items-center gap-2 bg-card py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <Checkbox
                                        checked={
                                            chosen.length === ids.length
                                                ? true
                                                : chosen.length > 0
                                                  ? 'indeterminate'
                                                  : false
                                        }
                                        onCheckedChange={(on) =>
                                            toggle(ids, on === true)
                                        }
                                    />
                                    {g.title} · {chosen.length}/{ids.length}
                                </label>
                                <div className="grid gap-1.5 sm:grid-cols-2">
                                    {g.list.map((mid) => (
                                        <label
                                            key={mid.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={selected.has(
                                                    String(mid.id),
                                                )}
                                                onCheckedChange={(on) =>
                                                    toggle(
                                                        [String(mid.id)],
                                                        on === true,
                                                    )
                                                }
                                            />
                                            <span className="font-mono text-xs">
                                                {mid.mid}
                                            </span>
                                            <span
                                                className="truncate text-muted-foreground"
                                                title={[
                                                    mid.client,
                                                    mid.company,
                                                    mid.merchant,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' › ')}
                                            >
                                                {mid.merchant}
                                                {mid.company &&
                                                    mid.company !==
                                                        mid.merchant &&
                                                    ` · ${mid.company}`}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                {mids.every((m) => !matches(m)) && (
                    <p className="py-4 text-center text-sm text-muted-foreground">
                        No MID matches the search.
                    </p>
                )}
            </div>

            <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>{value.length} selected</span>
                {value.length > 0 && (
                    <button
                        type="button"
                        className="text-primary hover:underline"
                        onClick={() => onChange([])}
                    >
                        Clear selection
                    </button>
                )}
            </div>
        </div>
    );
}
