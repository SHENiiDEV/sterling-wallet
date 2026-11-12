import { Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';

type Signals = {
    top_bin: string | null;
    top_bin_share: number | null;
    bin_limit: number;
    heavy_cards: number;
    max_tries: number;
    attempts_limit: number;
    night_share: number | null;
    night_limit: number;
    flags: string[];
};

type OwnerRow = {
    client_id?: number;
    merchant: { public_id: string | null; name: string };
    mid: string | null;
    signals: Signals | Record<string, never>;
    sales: number;
    cards: number;
    new_cards: number;
    new_share: number | null;
    high_days: number;
    days: number;
    status: 'ok' | 'high' | 'building';
};

type DayRow = {
    day: string;
    sales: number;
    cards: number;
    new_cards: number;
    new_share: number | null;
    warming: boolean;
    high: boolean;
};

type Props = {
    days: number;
    by: 'merchant' | 'mid' | 'client';
    limit: number;
    warmup: number;
    from: string;
    to: string;
    owners: OwnerRow[];
    selected: {
        key: string;
        name: string;
        days: DayRow[];
        members?: (OwnerRow & { company: string | null })[] | null;
    } | null;
};

const WINDOWS = [7, 14, 30, 60, 90];

const percent = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(1)}%`;

const hasSignals = (s: OwnerRow['signals']): s is Signals => 'flags' in s;

/**
 * A traffic report from a Corefy payments export: pick the file, get the PDF or XLSX. Nothing is stored.
 */
function FileReport() {
    const [file, setFile] = useState<File | null>(null);
    const [title, setTitle] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const make = async (format: 'pdf' | 'xlsx') => {
        if (!file) {
            return;
        }
        setBusy(format);
        setError(null);
        const body = new FormData();
        body.append('file', file);
        body.append('format', format);
        if (title.trim() !== '') {
            body.append('title', title.trim());
        }
        const token = decodeURIComponent(
            (
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN=')) ?? ''
            ).split('=')[1] ?? '',
        );

        try {
            const response = await fetch(admin.traffic.file.url(), {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    'X-XSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });
            if (!response.ok) {
                const json = await response.json().catch(() => null);
                setError(
                    json?.errors?.file?.[0] ??
                        json?.message ??
                        `Failed (${response.status})`,
                );

                return;
            }
            const name =
                /filename="([^"]+)"/.exec(
                    response.headers.get('Content-Disposition') ?? '',
                )?.[1] ?? `traffic.${format}`;
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = name;
            link.click();
            URL.revokeObjectURL(url);
        } finally {
            setBusy(null);
        }
    };

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4 shadow-xs">
            <div>
                <h2 className="text-sm font-semibold">
                    Report from a Corefy file
                </h2>
                <p className="text-xs text-muted-foreground">
                    Upload a payments export (XLSX or CSV with status,
                    resolution, amount, created) and get the traffic report:
                    approval, decline reasons, scheme, issuer country. Routing
                    errors are left out. The file is not stored.
                </p>
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1 text-xs text-muted-foreground">
                    File
                    <Input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        className="w-72"
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                </label>
                <label className="grid gap-1 text-xs text-muted-foreground">
                    Title (optional)
                    <Input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="taken from the file"
                        className="w-56"
                    />
                </label>
                {(['pdf', 'xlsx'] as const).map((format) => (
                    <Button
                        key={format}
                        variant="outline"
                        size="sm"
                        disabled={!file || busy !== null}
                        onClick={() => void make(format)}
                    >
                        <Download />
                        {busy === format ? 'Working…' : format.toUpperCase()}
                    </Button>
                ))}
            </div>
            {error && <p className="text-sm text-destructive">{error}</p>}
        </section>
    );
}

function Status({ status }: { status: OwnerRow['status'] }) {
    const label = {
        ok: 'Normal',
        high: 'Too many new cards',
        building: 'Building history',
    }[status];

    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-xs font-medium',
                status === 'high' && 'bg-rose-500/10 text-rose-600',
                status === 'ok' && 'bg-emerald-500/10 text-emerald-600',
                status === 'building' && 'bg-muted text-muted-foreground',
            )}
        >
            {label}
        </span>
    );
}

function Switch<T extends string | number>({
    options,
    value,
    href,
    label,
}: {
    options: T[];
    value: T;
    href: (option: T) => string;
    label: (option: T) => string;
}) {
    return (
        <div className="inline-flex rounded-lg border p-0.5 text-sm">
            {options.map((option) => (
                <Link
                    key={option}
                    href={href(option)}
                    className={
                        value === option
                            ? 'rounded-md bg-primary px-3 py-1 text-primary-foreground'
                            : 'px-3 py-1 text-muted-foreground'
                    }
                >
                    {label(option)}
                </Link>
            ))}
        </div>
    );
}

function SignalCells({ signals }: { signals: OwnerRow['signals'] }) {
    if (!hasSignals(signals)) {
        return (
            <TableCell
                colSpan={3}
                className="text-center text-muted-foreground"
            >
                —
            </TableCell>
        );
    }
    const red = (flag: string) =>
        signals.flags.includes(flag) && 'font-medium text-rose-600';

    return (
        <>
            <TableCell
                className={cn('text-right tabular-nums', red('bin'))}
                title={`One BIN over ${signals.bin_limit}% of the sales is flagged`}
            >
                {signals.top_bin ?? '—'}{' '}
                <span className="text-xs">
                    {percent(signals.top_bin_share)}
                </span>
            </TableCell>
            <TableCell
                className={cn('text-right tabular-nums', red('attempts'))}
                title={`Cards tried at least ${signals.attempts_limit} times in a day`}
            >
                {signals.heavy_cards}
                {signals.heavy_cards > 0 && (
                    <span className="text-xs"> (max {signals.max_tries}×)</span>
                )}
            </TableCell>
            <TableCell
                className={cn('text-right tabular-nums', red('night'))}
                title={`Sales at night over ${signals.night_limit}% are flagged`}
            >
                {percent(signals.night_share)}
            </TableCell>
        </>
    );
}

export default function Traffic({
    days,
    by,
    limit,
    warmup,
    from,
    to,
    owners,
    selected,
}: Props) {
    const url = (query: Record<string, string | number>) =>
        admin.traffic.index.url({ query });
    const unit = by === 'mid' ? 'MID' : by === 'client' ? 'client' : 'merchant';
    const ownerQuery = (o: OwnerRow): Record<string, string> =>
        by === 'mid'
            ? { by, mid: o.mid ?? '' }
            : by === 'client'
              ? { by, client: String(o.client_id ?? '') }
              : { by, merchant: o.merchant.public_id ?? '' };
    const isSelected = (o: OwnerRow) =>
        selected !== null &&
        selected.key ===
            (by === 'mid'
                ? o.mid
                : by === 'client'
                  ? String(o.client_id)
                  : o.merchant.public_id);

    return (
        <>
            <Head title="Card traffic" />
            <PageBody>
                <PageHeader
                    title="Card traffic"
                    description={`Traffic quality per ${unit}, ${formatDate(from)} – ${formatDate(to)}. Trusted traffic keeps new cards under ${limit}%.`}
                    actions={
                        <>
                            <Switch
                                options={['merchant', 'mid', 'client'] as const}
                                value={by}
                                href={(k) => url({ days, by: k })}
                                label={(k) =>
                                    k === 'mid'
                                        ? 'By MID'
                                        : k === 'client'
                                          ? 'By client'
                                          : 'By merchant'
                                }
                            />
                            <Switch
                                options={WINDOWS}
                                value={days}
                                href={(w) => url({ days: w, by })}
                                label={(w) => `${w}d`}
                            />
                        </>
                    }
                />
                <p className="text-sm text-muted-foreground">
                    A card is the BIN plus the last four digits. It counts as
                    new on the day it is first seen on that {unit}. The share is
                    new cards ÷ cards paying that day. The first {warmup} days
                    of the history are not judged: with no history every card is
                    new.
                </p>
                <p className="text-sm text-muted-foreground">
                    Other signals over the period: one BIN carrying too much of
                    the sales, the same card tried many times in a day (card
                    testing) and the share of sales at night (00:00–06:00 UTC).
                    A red value is above its limit.
                </p>

                <FileReport />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {by === 'mid'
                                        ? 'MID'
                                        : by === 'client'
                                          ? 'Client'
                                          : 'Merchant'}
                                </TableHead>
                                <TableHead className="text-right">
                                    Sales
                                </TableHead>
                                <TableHead className="text-right">
                                    Cards
                                </TableHead>
                                <TableHead className="text-right">
                                    New cards
                                </TableHead>
                                <TableHead className="text-right">
                                    New share
                                </TableHead>
                                <TableHead className="text-right">
                                    Days over {limit}%
                                </TableHead>
                                <TableHead className="text-right">
                                    Top BIN
                                </TableHead>
                                <TableHead className="text-right">
                                    Cards tried many times
                                </TableHead>
                                <TableHead className="text-right">
                                    Night
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {owners.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={10}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        No card sales in this period.
                                    </TableCell>
                                </TableRow>
                            )}
                            {owners.map((o) => (
                                <TableRow
                                    key={
                                        o.mid ??
                                        o.merchant.public_id ??
                                        o.client_id
                                    }
                                    className={cn(isSelected(o) && 'bg-accent')}
                                >
                                    <TableCell>
                                        <Link
                                            href={url({
                                                days,
                                                ...ownerQuery(o),
                                            })}
                                            className="font-medium hover:underline"
                                        >
                                            {by === 'mid' ? (
                                                <>
                                                    <span className="font-mono">
                                                        {o.mid}
                                                    </span>{' '}
                                                    <span className="text-xs font-normal text-muted-foreground">
                                                        {o.merchant.name}
                                                    </span>
                                                </>
                                            ) : (
                                                o.merchant.name
                                            )}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {o.sales}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {o.cards}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {o.new_cards}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right font-medium tabular-nums',
                                            o.status === 'high' &&
                                                'text-rose-600',
                                        )}
                                    >
                                        {percent(o.new_share)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {o.high_days}
                                    </TableCell>
                                    <SignalCells signals={o.signals} />
                                    <TableCell>
                                        <Status status={o.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                {selected && (
                    <section className="grid gap-3">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold">
                                {selected.name} by day
                            </h2>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={url({ days, by })}>Close</Link>
                            </Button>
                        </div>
                        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Day</TableHead>
                                        <TableHead className="text-right">
                                            Sales
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Cards
                                        </TableHead>
                                        <TableHead className="text-right">
                                            New cards
                                        </TableHead>
                                        <TableHead className="text-right">
                                            New share
                                        </TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {selected.days.map((d) => (
                                        <TableRow key={d.day}>
                                            <TableCell>
                                                {formatDate(d.day)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {d.sales}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {d.cards}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {d.new_cards}
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    d.high &&
                                                        'font-medium text-rose-600',
                                                )}
                                            >
                                                {percent(d.new_share)}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {d.warming
                                                    ? 'building history'
                                                    : d.high
                                                      ? `over ${limit}%`
                                                      : ''}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {selected.members && (
                            <>
                                <h2 className="text-sm font-semibold">
                                    Companies and MIDs of {selected.name}
                                </h2>
                                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Company</TableHead>
                                                <TableHead>Merchant</TableHead>
                                                <TableHead>MID</TableHead>
                                                <TableHead className="text-right">
                                                    Sales
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Cards
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    New share
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Top BIN
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Cards tried many times
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Night
                                                </TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {selected.members.map((m) => (
                                                <TableRow key={m.mid}>
                                                    <TableCell>
                                                        {m.company ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {m.merchant.name}
                                                    </TableCell>
                                                    <TableCell className="font-mono">
                                                        {m.mid}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {m.sales}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {m.cards}
                                                    </TableCell>
                                                    <TableCell
                                                        className={cn(
                                                            'text-right font-medium tabular-nums',
                                                            m.status ===
                                                                'high' &&
                                                                'text-rose-600',
                                                        )}
                                                    >
                                                        {percent(m.new_share)}
                                                    </TableCell>
                                                    <SignalCells
                                                        signals={m.signals}
                                                    />
                                                    <TableCell>
                                                        <Status
                                                            status={m.status}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </>
                        )}
                    </section>
                )}
            </PageBody>
        </>
    );
}
