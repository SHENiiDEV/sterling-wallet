import { Download } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const ALL = 'all';

const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Monday-based week and calendar month shortcuts, relative to today. */
function presets(): { label: string; from: string; to: string }[] {
    const today = new Date();
    const monday = new Date(today);
    monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
    const lastMonday = new Date(monday);
    lastMonday.setDate(monday.getDate() - 7);
    const lastSunday = new Date(monday);
    lastSunday.setDate(monday.getDate() - 1);
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    return [
        { label: 'Last week', from: iso(lastMonday), to: iso(lastSunday) },
        {
            label: 'This month',
            from: iso(new Date(today.getFullYear(), today.getMonth(), 1)),
            to: iso(yesterday),
        },
        {
            label: 'Last month',
            from: iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
            to: iso(new Date(today.getFullYear(), today.getMonth(), 0)),
        },
        {
            label: 'Last 30 days',
            from: iso(
                new Date(
                    today.getFullYear(),
                    today.getMonth(),
                    today.getDate() - 30,
                ),
            ),
            to: iso(yesterday),
        },
    ];
}

const CARD_LISTS: [string, string][] = [
    ['all', 'All cards'],
    ['white1', 'White list · 1+ successful'],
    ['white3', 'White list · 3+ successful'],
    ['white5', 'White list · 5+ successful'],
    ['white7', 'White list · 7+ successful'],
    ['white10', 'White list · 10+ successful'],
    ['black', 'Black list · always declined'],
    ['insufficient', 'Black list · insufficient funds'],
];

/**
 * One statement for a whole period (a week, a month, any dates) for a client, or one of its companies, as PDF or
 * XLSX. Built from the finished daily reports, so it always agrees with the payouts.
 */
export function PeriodReport({
    url,
    trafficUrl,
    cardsUrl,
    companies,
}: {
    url: string;
    trafficUrl?: string;
    cardsUrl?: string;
    companies: { id: number; name: string }[];
}) {
    const shortcuts = presets();
    const [from, setFrom] = useState(shortcuts[0].from);
    const [to, setTo] = useState(shortcuts[0].to);
    const [company, setCompany] = useState(ALL);
    const [list, setList] = useState('white3');
    const valid = from !== '' && to !== '' && from <= to;
    const href = (format: 'pdf' | 'xlsx' | 'csv', base = url) =>
        `${base}?date=${from}&to=${to}&format=${format}${company === ALL ? '' : `&company=${company}`}${format === 'csv' ? `&list=${list}` : ''}`;

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4 shadow-xs">
            <div>
                <h2 className="text-sm font-semibold">Period statement</h2>
                <p className="text-xs text-muted-foreground">
                    One statement for any period: totals, a day-by-day table and
                    each MID. Days still waiting for data are listed in it.
                </p>
            </div>
            <div className="flex flex-wrap gap-1">
                {shortcuts.map((p) => (
                    <Button
                        key={p.label}
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            setFrom(p.from);
                            setTo(p.to);
                        }}
                    >
                        {p.label}
                    </Button>
                ))}
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <label className="grid gap-1 text-xs text-muted-foreground">
                    From
                    <Input
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="w-40"
                    />
                </label>
                <label className="grid gap-1 text-xs text-muted-foreground">
                    To
                    <Input
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-40"
                    />
                </label>
                {companies.length > 1 && (
                    <label className="grid gap-1 text-xs text-muted-foreground">
                        Company
                        <Select value={company} onValueChange={setCompany}>
                            <SelectTrigger className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>
                                    All companies
                                </SelectItem>
                                {companies.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>
                )}
                {(['pdf', 'xlsx'] as const).map((format) => (
                    <Button
                        key={format}
                        variant="outline"
                        size="sm"
                        asChild
                        disabled={!valid}
                    >
                        <a href={valid ? href(format) : undefined}>
                            <Download />
                            {format.toUpperCase()}
                        </a>
                    </Button>
                ))}
                {trafficUrl &&
                    (['pdf', 'xlsx'] as const).map((format) => (
                        <Button
                            key={`traffic-${format}`}
                            variant="outline"
                            size="sm"
                            asChild
                            disabled={!valid}
                        >
                            <a
                                href={
                                    valid ? href(format, trafficUrl) : undefined
                                }
                            >
                                <Download />
                                Traffic {format.toUpperCase()}
                            </a>
                        </Button>
                    ))}
            </div>
            {cardsUrl && (
                <div className="grid gap-2 border-t pt-3">
                    <p className="text-xs text-muted-foreground">
                        Cards of the client as masks (123456*7890) for white and
                        black lists. The XLSX has every list; the CSV is one
                        list.
                    </p>
                    <div className="flex flex-wrap items-end gap-3">
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            disabled={!valid}
                        >
                            <a
                                href={
                                    valid ? href('xlsx', cardsUrl) : undefined
                                }
                            >
                                <Download />
                                Cards XLSX
                            </a>
                        </Button>
                        <Select value={list} onValueChange={setList}>
                            <SelectTrigger className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {CARD_LISTS.map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            disabled={!valid}
                        >
                            <a href={valid ? href('csv', cardsUrl) : undefined}>
                                <Download />
                                List CSV
                            </a>
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
