import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowUpRight,
    Bot,
    CalendarClock,
    CheckCircle2,
    Coins,
    FolderOpen,
    Store,
    Users,
    Wallet,
} from 'lucide-react';
import { PageBody } from '@/components/admin/page-header';
import { StatCard } from '@/components/admin/stat-card';
import {
    StatusBadge,
    StatusDot,
    statusColorClasses,
} from '@/components/admin/status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import admin from '@/routes/admin';
import type { DocumentItem, DocumentStatus } from '@/types';

type ActivityItem = {
    id: number;
    type: string;
    user: string | null;
    document: { id: number; title: string };
    to_status: DocumentStatus | null;
    created_at: string;
};

type Props = {
    stats: {
        documents_open: number;
        documents_overdue: number;
        documents_signed_30d: number;
        staff: number;
    };
    pipeline: DocumentStatus[];
    attention: DocumentItem[];
    activity: ActivityItem[];
    reportAlerts: {
        key: string;
        label: string;
        count: number;
        detail: string | null;
    }[];
};

const roadmap = [
    {
        icon: Store,
        title: 'Merchants & MIDs',
        text: 'USD / EUR / GBP MIDs per merchant, tariffs and reserves',
    },
    {
        icon: Bot,
        title: 'Report bots',
        text: 'Cardaq & Corefy exports collected automatically',
    },
    {
        icon: Wallet,
        title: 'Settlements',
        text: 'Daily reports, rolling reserve and USDC payouts',
    },
    {
        icon: Coins,
        title: 'Profit & share',
        text: 'Margin per merchant and partner splits by month',
    },
];

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 18 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard({
    stats,
    pipeline,
    attention,
    activity,
    reportAlerts,
}: Props) {
    const { auth } = usePage().props;
    const total = pipeline.reduce(
        (sum, status) => sum + (status.documents_count ?? 0),
        0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <PageBody>
                <div className="flex flex-col gap-1">
                    <p className="text-sm text-muted-foreground">
                        {new Intl.DateTimeFormat('en-GB', {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                        }).format(new Date())}
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {greeting()}, {auth.user.name.split(' ')[0]}
                    </h1>
                </div>

                {reportAlerts.length > 0 && (
                    <div className="grid gap-2 rounded-xl border border-warning/40 bg-warning/5 p-4">
                        <p className="flex items-center gap-2 text-sm font-semibold">
                            <AlertTriangle className="size-4 text-warning" />
                            Reports need attention
                        </p>
                        <ul className="grid gap-1 text-sm">
                            {reportAlerts.map((alert) => (
                                <li
                                    key={alert.key}
                                    className="flex flex-wrap items-center gap-x-2"
                                >
                                    <span className="font-medium tabular-nums">
                                        {alert.count}
                                    </span>
                                    <span>{alert.label}</span>
                                    {alert.detail && (
                                        <span className="text-muted-foreground">
                                            — {alert.detail}
                                        </span>
                                    )}
                                    {alert.key === 'review_mids' && (
                                        <Link
                                            href={admin.merchants.index({
                                                query: { status: 'review' },
                                            })}
                                            className="text-primary hover:underline"
                                        >
                                            Review merchants
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Open documents"
                        value={stats.documents_open}
                        hint="Not yet in a final status"
                        icon={FolderOpen}
                        tone="brand"
                    />
                    <StatCard
                        label="Overdue"
                        value={stats.documents_overdue}
                        hint="Past due date and still open"
                        icon={AlertTriangle}
                        tone={stats.documents_overdue ? 'danger' : 'default'}
                    />
                    <StatCard
                        label="Closed · 30 days"
                        value={stats.documents_signed_30d}
                        hint="Moved to a final status"
                        icon={CheckCircle2}
                        tone="success"
                    />
                    <StatCard
                        label="Active team"
                        value={stats.staff}
                        hint="Admins with console access"
                        icon={Users}
                    />
                </div>

                <section className="rounded-xl border bg-card p-5 shadow-xs">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Document pipeline
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {total} document{total === 1 ? '' : 's'} across{' '}
                                {pipeline.length} statuses
                            </p>
                        </div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={admin.documents.index()}>
                                Open Document Center
                                <ArrowUpRight />
                            </Link>
                        </Button>
                    </div>
                    <div className="mt-4 flex h-2.5 overflow-hidden rounded-full bg-muted">
                        {total > 0 &&
                            pipeline.map((status) =>
                                status.documents_count ? (
                                    <div
                                        key={status.id}
                                        className={cn(
                                            'h-full border-r-2 border-card last:border-r-0',
                                            statusColorClasses[status.color]
                                                ?.bar,
                                        )}
                                        style={{
                                            width: `${((status.documents_count ?? 0) / total) * 100}%`,
                                        }}
                                        title={`${status.name}: ${status.documents_count}`}
                                    />
                                ) : null,
                            )}
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {pipeline.map((status) => (
                            <Link
                                key={status.id}
                                href={admin.documents.index({
                                    query: { status: status.id },
                                })}
                                className="rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/60"
                            >
                                <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <StatusDot color={status.color} />
                                    <span className="truncate">
                                        {status.name}
                                    </span>
                                </span>
                                <span className="mt-1 block text-xl font-semibold tabular-nums">
                                    {status.documents_count ?? 0}
                                </span>
                            </Link>
                        ))}
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center gap-2 border-b px-5 py-3.5">
                            <CalendarClock className="size-4 text-muted-foreground" />
                            <h2 className="text-sm font-semibold">Coming up</h2>
                        </header>
                        {attention.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                No open documents with a due date.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {attention.map((document) => (
                                    <li key={document.id}>
                                        <Link
                                            href={admin.documents.show(
                                                document.id,
                                            )}
                                            className="flex items-center gap-3 px-5 py-3 hover:bg-muted/40"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {document.title}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {document.counterparty ??
                                                        document.type_label}
                                                    {document.owner &&
                                                        ` · ${document.owner.name}`}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                name={document.status.name}
                                                color={document.status.color}
                                                className="hidden sm:inline-flex"
                                            />
                                            <span
                                                className={cn(
                                                    'w-24 shrink-0 text-right text-sm tabular-nums',
                                                    document.is_overdue
                                                        ? 'font-medium text-destructive-foreground'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {formatDate(document.due_date)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="rounded-xl border bg-card shadow-xs">
                        <header className="flex items-center gap-2 border-b px-5 py-3.5">
                            <Activity className="size-4 text-muted-foreground" />
                            <h2 className="text-sm font-semibold">
                                Recent activity
                            </h2>
                        </header>
                        {activity.length === 0 ? (
                            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                Activity from the Document Center shows up here.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {activity.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-start gap-3 px-5 py-3"
                                    >
                                        <span className="mt-1.5">
                                            {item.to_status ? (
                                                <StatusDot
                                                    color={item.to_status.color}
                                                />
                                            ) : (
                                                <span className="block size-2 rounded-full bg-muted-foreground/40" />
                                            )}
                                        </span>
                                        <p className="min-w-0 flex-1 text-sm">
                                            <span className="font-medium">
                                                {item.user ?? 'System'}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                {activityVerb(item)}
                                            </span>{' '}
                                            <Link
                                                href={admin.documents.show(
                                                    item.document.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {item.document.title}
                                            </Link>
                                        </p>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {formatRelative(item.created_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section className="rounded-xl border border-dashed p-5">
                    <h2 className="text-sm font-semibold">Coming next</h2>
                    <p className="text-sm text-muted-foreground">
                        Modules being moved over from the current platform.
                    </p>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {roadmap.map((item) => (
                            <div
                                key={item.title}
                                className="flex gap-3 rounded-lg bg-muted/40 p-3"
                            >
                                <item.icon className="mt-0.5 size-4 shrink-0 text-brand" />
                                <div>
                                    <p className="text-sm font-medium">
                                        {item.title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {item.text}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </PageBody>
        </>
    );
}

function activityVerb(item: ActivityItem): string {
    switch (item.type) {
        case 'created':
            return 'created';
        case 'status_changed':
            return `moved to ${item.to_status?.name ?? 'a new status'}`;
        case 'file_uploaded':
            return 'uploaded a file to';
        case 'file_removed':
            return 'removed a file from';
        case 'comment':
            return 'commented on';
        default:
            return 'updated';
    }
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: admin.dashboard() }],
};
