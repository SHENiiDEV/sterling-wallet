import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/admin/empty-state';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Issue = { code: string; label: string; detail: string | null };

type Props = {
    merchants: {
        public_id: string;
        name: string;
        company: string | null;
        client: string | null;
        issues: Issue[];
        mids: {
            id: number;
            mid: string;
            currency: string;
            pair: string;
            issues: Issue[];
        }[];
    }[];
    counts: Record<string, number>;
    codes: Record<string, string>;
    filters: { issue?: string };
};

function Chip({ issue }: { issue: Issue }) {
    return (
        <span className="rounded-md bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-amber-500/25 ring-inset dark:text-amber-300">
            {issue.label}
            {issue.detail && ` · ${issue.detail}`}
        </span>
    );
}

export default function Checklist({
    merchants,
    counts,
    codes,
    filters,
}: Props) {
    const [search, setSearch] = useState('');
    const needle = search.trim().toLowerCase();
    const visible = merchants.filter(
        (m) =>
            needle === '' ||
            [m.name, m.company, m.client, ...m.mids.map((x) => x.mid)].some(
                (v) => v?.toLowerCase().includes(needle),
            ),
    );
    const go = (issue: string | null) =>
        router.get(admin.checklist.index.url(), issue ? { issue } : {}, {
            preserveScroll: true,
            preserveState: true,
        });

    return (
        <>
            <Head title="Checklist" />
            <PageBody>
                <PageHeader
                    title="Checklist"
                    description="What is missing so merchants and terminals can be processed, reported, planned and paid out without surprises."
                />

                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => go(null)}
                        className={cn(
                            'rounded-md border px-3 py-1.5 text-sm font-medium',
                            !filters.issue
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        All
                    </button>
                    {Object.entries(codes)
                        .filter(([code]) => (counts[code] ?? 0) > 0)
                        .map(([code, label]) => (
                            <button
                                key={code}
                                type="button"
                                onClick={() => go(code)}
                                className={cn(
                                    'rounded-md border px-3 py-1.5 text-sm font-medium',
                                    filters.issue === code
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                                <span className="ml-1.5 opacity-70">
                                    {counts[code]}
                                </span>
                            </button>
                        ))}
                </div>

                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search merchant, company, client or MID…"
                    className="max-w-md"
                />

                {visible.length === 0 ? (
                    <EmptyState
                        icon={CheckCircle2}
                        title="Everything is in place"
                        description="No live merchant or terminal is missing anything."
                    />
                ) : (
                    <div className="grid gap-3 xl:grid-cols-2">
                        {visible.map((m) => (
                            <section
                                key={m.public_id}
                                className="rounded-xl border bg-card shadow-xs"
                            >
                                <header className="flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                                    <Link
                                        href={admin.merchants.show(m.public_id)}
                                        className="font-medium hover:underline"
                                    >
                                        {m.name}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">
                                        {[m.client, m.company]
                                            .filter(Boolean)
                                            .join(' › ')}
                                    </span>
                                </header>
                                <div className="grid gap-3 px-4 py-3">
                                    {m.issues.length > 0 && (
                                        <div className="flex flex-wrap gap-1.5">
                                            {m.issues.map((i) => (
                                                <Chip key={i.code} issue={i} />
                                            ))}
                                        </div>
                                    )}
                                    {m.mids.map((mid) => (
                                        <div
                                            key={mid.id}
                                            className="grid gap-1.5"
                                        >
                                            <div className="text-xs text-muted-foreground">
                                                <span className="font-mono">
                                                    {mid.mid}
                                                </span>{' '}
                                                · {mid.currency} · {mid.pair}
                                            </div>
                                            <div className="flex flex-wrap gap-1.5">
                                                {mid.issues.map((i, n) => (
                                                    <Chip
                                                        key={`${i.code}-${n}`}
                                                        issue={i}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}
            </PageBody>
        </>
    );
}

Checklist.layout = {
    breadcrumbs: [{ title: 'Checklist', href: admin.checklist.index() }],
};
