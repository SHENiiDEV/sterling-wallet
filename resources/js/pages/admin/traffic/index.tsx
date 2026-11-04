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

type MerchantRow = {
    merchant: { public_id: string; name: string };
    sales: number;
    cards: number;
    new_cards: number;
    judged_cards: number;
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
    limit: number;
    warmup: number;
    from: string;
    to: string;
    merchants: MerchantRow[];
    selected: { public_id: string; name: string; days: DayRow[] } | null;
};

const WINDOWS = [7, 14, 30, 60, 90];

const percent = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(1)}%`;

function Status({ status }: { status: MerchantRow['status'] }) {
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

export default function Traffic({
    days,
    limit,
    warmup,
    from,
    to,
    merchants,
    selected,
}: Props) {
    const url = (query: Record<string, string | number>) =>
        admin.traffic.index.url({ query });

    return (
        <>
            <Head title="Card traffic" />
            <PageBody>
                <PageHeader
                    title="Card traffic"
                    description={`Share of first-time cards per merchant, ${formatDate(from)} – ${formatDate(to)}. Trusted traffic stays under ${limit}%.`}
                    actions={
                        <div className="inline-flex rounded-lg border p-0.5 text-sm">
                            {WINDOWS.map((w) => (
                                <Link
                                    key={w}
                                    href={url({
                                        days: w,
                                        ...(selected
                                            ? { merchant: selected.public_id }
                                            : {}),
                                    })}
                                    className={
                                        days === w
                                            ? 'rounded-md bg-primary px-3 py-1 text-primary-foreground'
                                            : 'px-3 py-1 text-muted-foreground'
                                    }
                                >
                                    {w}d
                                </Link>
                            ))}
                        </div>
                    }
                />
                <p className="text-sm text-muted-foreground">
                    A card is the BIN plus the last four digits. It counts as
                    new on the day it is first seen on that merchant. The share
                    is new cards ÷ cards paying that day. The first {warmup}{' '}
                    days of a merchant&apos;s history are not judged: with no
                    history every card is new.
                </p>

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Merchant</TableHead>
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
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {merchants.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        No card sales in this period.
                                    </TableCell>
                                </TableRow>
                            )}
                            {merchants.map((m) => (
                                <TableRow
                                    key={m.merchant.public_id}
                                    className={cn(
                                        selected?.public_id ===
                                            m.merchant.public_id && 'bg-accent',
                                    )}
                                >
                                    <TableCell>
                                        <Link
                                            href={url({
                                                days,
                                                merchant: m.merchant.public_id,
                                            })}
                                            className="font-medium hover:underline"
                                        >
                                            {m.merchant.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {m.sales}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {m.cards}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {m.new_cards}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right font-medium tabular-nums',
                                            m.status === 'high' &&
                                                'text-rose-600',
                                        )}
                                    >
                                        {percent(m.new_share)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {m.high_days}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={m.status} />
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
                                <Link href={url({ days })}>Close</Link>
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
