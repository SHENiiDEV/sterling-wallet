import { router } from '@inertiajs/react';

export type ApprovalRow = {
    label: string;
    from: string;
    to: string;
    attempts: number;
    approved: number;
    declined: number;
    rate: number | null;
};

export type Approval = {
    group: 'day' | 'week' | 'month';
    rows: ApprovalRow[];
    totals: {
        attempts: number;
        approved: number;
        declined: number;
        rate: number | null;
    };
    routing_errors: number;
};

const GROUPS: { key: Approval['group']; label: string; hint: string }[] = [
    { key: 'day', label: 'Days', hint: 'last 30 days' },
    { key: 'week', label: 'Weeks', hint: 'last 12 weeks' },
    { key: 'month', label: 'Months', hint: 'last 12 months' },
];

const percent = (value: number | null) =>
    value === null ? '—' : `${value.toFixed(1)}%`;

/**
 * Approval rate from the gateway's attempts: approved sales over all attempts, by day, week or month.
 */
export function ApprovalRate({ approval }: { approval: Approval }) {
    const current = GROUPS.find((g) => g.key === approval.group) ?? GROUPS[1];
    const rows = [...approval.rows].reverse();
    const peak = Math.max(1, ...approval.rows.map((r) => r.attempts));

    const choose = (group: Approval['group']) =>
        router.get(
            window.location.pathname,
            { approval: group },
            {
                only: ['approval'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );

    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
                <div>
                    <h2 className="text-sm font-semibold">
                        Approval rate · {current.hint}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Approved sales over all attempts at the gateway. Routing
                        errors are left out
                        {approval.routing_errors > 0
                            ? ` (${approval.routing_errors.toLocaleString('en-US')}).`
                            : '.'}
                    </p>
                </div>
                <div className="flex gap-1 rounded-lg border p-0.5">
                    {GROUPS.map((g) => (
                        <button
                            key={g.key}
                            type="button"
                            onClick={() => choose(g.key)}
                            className={`rounded-md px-3 py-1 text-xs font-medium transition-colors ${
                                g.key === approval.group
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {g.label}
                        </button>
                    ))}
                </div>
            </header>
            <div className="grid grid-cols-3 gap-px border-b bg-border text-center">
                {[
                    ['Approval rate', percent(approval.totals.rate)],
                    [
                        'Approved',
                        approval.totals.approved.toLocaleString('en-US'),
                    ],
                    [
                        'Declined',
                        approval.totals.declined.toLocaleString('en-US'),
                    ],
                ].map(([label, value]) => (
                    <div key={label} className="bg-card px-3 py-3">
                        <div className="text-xs text-muted-foreground">
                            {label}
                        </div>
                        <div className="text-lg font-semibold tabular-nums">
                            {value}
                        </div>
                    </div>
                ))}
            </div>
            <div className="max-h-96 overflow-y-auto">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                        <tr className="border-b">
                            <th className="px-5 py-2 text-left font-medium">
                                Period
                            </th>
                            <th className="px-3 py-2 text-right font-medium">
                                Attempts
                            </th>
                            <th className="px-3 py-2 text-right font-medium">
                                Approved
                            </th>
                            <th className="px-3 py-2 text-right font-medium">
                                Declined
                            </th>
                            <th className="w-40 px-5 py-2 text-right font-medium">
                                Approval rate
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.from}
                                className="border-b last:border-b-0"
                            >
                                <td className="px-5 py-2">{row.label}</td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {row.attempts.toLocaleString('en-US')}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {row.approved.toLocaleString('en-US')}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {row.declined.toLocaleString('en-US')}
                                </td>
                                <td className="px-5 py-2">
                                    <div className="flex items-center justify-end gap-2">
                                        <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-primary"
                                                style={{
                                                    width: `${row.rate ?? 0}%`,
                                                    opacity:
                                                        0.35 +
                                                        0.65 *
                                                            (row.attempts /
                                                                peak),
                                                }}
                                            />
                                        </div>
                                        <span className="w-12 text-right font-medium tabular-nums">
                                            {percent(row.rate)}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
