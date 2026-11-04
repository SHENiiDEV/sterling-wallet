import { Head, Link } from '@inertiajs/react';
import { PageBody, PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
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
    by: 'merchant' | 'mid';
    limit: number;
    warmup: number;
    from: string;
    to: string;
    owners: OwnerRow[];
    selected: { key: string; name: string; days: DayRow[] } | null;
};

const WINDOWS = [7, 14, 30, 60, 90];

const percent = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(1)}%`;

const hasSignals = (s: OwnerRow['signals']): s is Signals => 'flags' in s;

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
    const ownerQuery = (o: OwnerRow) =>
        by === 'mid'
            ? { by, mid: o.mid ?? '' }
            : { by, merchant: o.merchant.public_id ?? '' };
    const isSelected = (o: OwnerRow) =>
        selected !== null &&
        selected.key === (by === 'mid' ? o.mid : o.merchant.public_id);

    return (
        <>
            <Head title="Card traffic" />
            <PageBody>
                <PageHeader
                    title="Card traffic"
                    description={`Traffic quality per ${by === 'mid' ? 'MID' : 'merchant'}, ${formatDate(from)} – ${formatDate(to)}. Trusted traffic keeps new cards under ${limit}%.`}
                    actions={
                        <>
                            <Switch
                                options={['merchant', 'mid'] as const}
                                value={by}
                                href={(k) => url({ days, by: k })}
                                label={(k) =>
                                    k === 'mid' ? 'By MID' : 'By merchant'
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
                    new on the day it is first seen on that{' '}
                    {by === 'mid' ? 'MID' : 'merchant'}. The share is new cards
                    ÷ cards paying that day. The first {warmup} days of the
                    history are not judged: with no history every card is new.
                </p>
                <p className="text-sm text-muted-foreground">
                    Other signals over the period: one BIN carrying too much of
                    the sales, the same card tried many times in a day (card
                    testing) and the share of sales at night (00:00–06:00 UTC).
                    A red value is above its limit.
                </p>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {by === 'mid' ? 'MID' : 'Merchant'}
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
                                    key={o.mid ?? o.merchant.public_id}
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
                    </section>
                )}
            </PageBody>
        </>
    );
}
