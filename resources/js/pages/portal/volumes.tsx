import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { BarChart } from '@/components/admin/bar-chart';
import { Meter } from '@/components/admin/meter';
import { CurrencyBadge } from '@/components/admin/tone-badge';
import { PortalFilters } from '@/components/portal/portal';
import type { PortalShared } from '@/components/portal/portal';
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
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import portal from '@/routes/portal';

type Terminal = {
    id: number;
    mid: string;
    currency: string;
    merchant: string;
    volume: number;
    approved: number;
    declined: number;
    norm: number | null;
    norm_percent: number | null;
    shortfall: number | null;
    month_volume: number;
    month_limit: number | null;
    month_percent: number | null;
};

type Props = PortalShared & {
    day: string;
    shortcuts: { date: string; label: string }[];
    dataThrough: string | null;
    rows: Terminal[];
    selected: {
        terminal: Terminal;
        days: {
            date: string;
            approved: number;
            declined: number;
            volume: number;
            norm: number | null;
            norm_percent: number | null;
        }[];
    } | null;
};

export default function PortalVolumes(props: Props) {
    const { day, shortcuts, dataThrough, rows, selected } = props;
    const extra = (query: Record<string, string | number | null>) =>
        Object.fromEntries(
            Object.entries({
                company: props.portal.company
                    ? String(props.portal.company.id)
                    : null,
                merchant: props.portal.merchant,
                ...query,
            }).filter(([, v]) => v),
        );
    const go = (query: Record<string, string | number | null>) =>
        router.get(portal.volumes.url(), extra(query), {
            preserveScroll: true,
            preserveState: true,
        });
    const step = (days: number) => {
        const d = new Date(`${day}T12:00:00`);
        d.setDate(d.getDate() + days);

        return d.toISOString().slice(0, 10);
    };
    const newest = shortcuts[0].date;

    return (
        <>
            <Head title="Volumes" />
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Volumes
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        How much of its daily norm each terminal took, from the
                        daily gateway reports.
                        {dataThrough &&
                            ` Data up to ${formatDate(dataThrough)}.`}
                    </p>
                </div>
                <PortalFilters
                    shared={props}
                    url={portal.volumes.url()}
                    extra={{ day }}
                />
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {shortcuts.map((s) => (
                    <Button
                        key={s.date}
                        size="sm"
                        variant={s.date === day ? 'default' : 'outline'}
                        onClick={() => go({ day: s.date })}
                    >
                        {s.label}
                    </Button>
                ))}
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        aria-label="Previous day"
                        onClick={() => go({ day: step(-1) })}
                    >
                        <ChevronLeft />
                    </Button>
                    <Input
                        type="date"
                        value={day}
                        max={newest}
                        onChange={(e) =>
                            e.target.value && go({ day: e.target.value })
                        }
                        className="h-8 w-40"
                        aria-label="Day"
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-8"
                        aria-label="Next day"
                        disabled={day >= newest}
                        onClick={() => go({ day: step(1) })}
                    >
                        <ChevronRight />
                    </Button>
                </div>
            </div>

            <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
                {rows.length === 0 ? (
                    <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                        No terminals yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-5">Terminal</TableHead>
                                <TableHead className="text-right">
                                    Volume
                                </TableHead>
                                <TableHead className="text-right">
                                    Successful
                                </TableHead>
                                <TableHead>Daily norm</TableHead>
                                <TableHead className="text-right">
                                    Missing to norm
                                </TableHead>
                                <TableHead className="pr-5">
                                    This month
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((t) => (
                                <TableRow
                                    key={t.id}
                                    className={cn(
                                        'cursor-pointer',
                                        selected?.terminal.id === t.id &&
                                            'bg-accent/50',
                                    )}
                                    onClick={() => go({ day, mid: t.id })}
                                >
                                    <TableCell className="pl-5">
                                        <div className="flex items-center gap-2">
                                            <CurrencyBadge
                                                currency={t.currency}
                                            />
                                            <span className="font-mono text-xs">
                                                {t.mid}
                                            </span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {t.merchant}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatMoney(t.volume, t.currency)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {t.approved}
                                    </TableCell>
                                    <TableCell>
                                        <Meter
                                            goal
                                            percent={t.norm_percent}
                                            empty="no norm set"
                                        />
                                        {t.norm !== null && (
                                            <div className="text-xs text-muted-foreground">
                                                of{' '}
                                                {formatMoney(
                                                    t.norm,
                                                    t.currency,
                                                    0,
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {t.shortfall === null ? (
                                            '—'
                                        ) : t.shortfall === 0 ? (
                                            <span className="text-emerald-600">
                                                norm met
                                            </span>
                                        ) : (
                                            formatMoney(
                                                t.shortfall,
                                                t.currency,
                                                0,
                                            )
                                        )}
                                    </TableCell>
                                    <TableCell className="pr-5">
                                        <Meter
                                            percent={t.month_percent}
                                            empty="no limit"
                                        />
                                        <div className="text-xs text-muted-foreground">
                                            {formatMoney(
                                                t.month_volume,
                                                t.currency,
                                                0,
                                            )}
                                            {t.month_limit !== null &&
                                                ` of ${formatMoney(t.month_limit, t.currency, 0)}`}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </section>

            {selected && (
                <section className="grid gap-4">
                    <h2 className="text-sm font-semibold">
                        {selected.terminal.merchant} ·{' '}
                        <span className="font-mono">
                            {selected.terminal.mid}
                        </span>
                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                            last {selected.days.length} days
                        </span>
                    </h2>
                    <div className="rounded-xl border bg-card p-5 shadow-xs">
                        <h3 className="mb-3 text-sm font-semibold">
                            Successful volume per day
                        </h3>
                        <BarChart
                            label="Successful volume per day"
                            points={selected.days.map((d) => ({
                                date: d.date,
                                value: d.volume,
                            }))}
                            format={(v) =>
                                formatMoney(v, selected.terminal.currency, 0)
                            }
                        />
                    </div>
                    <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="pl-5">Day</TableHead>
                                    <TableHead className="text-right">
                                        Successful
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Volume
                                    </TableHead>
                                    <TableHead className="pr-5">
                                        Of daily norm
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {[...selected.days].reverse().map((d) => (
                                    <TableRow
                                        key={d.date}
                                        className={cn(
                                            d.date === day && 'bg-accent/50',
                                        )}
                                    >
                                        <TableCell className="pl-5">
                                            {formatDate(d.date)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {d.approved}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {formatMoney(
                                                d.volume,
                                                selected.terminal.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="pr-5">
                                            <Meter
                                                goal
                                                percent={d.norm_percent}
                                                empty="no norm"
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            )}
        </>
    );
}
