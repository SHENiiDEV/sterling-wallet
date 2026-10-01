import { router } from '@inertiajs/react';
import { Download, ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatMoney } from '@/lib/money';
import portal from '@/routes/portal';

export type Amount = { currency: string; amount: string };

export type PortalShared = {
    company: string | null;
    merchants: { public_id: string; name: string }[];
    merchant: string | null;
};

export type PortalReport = {
    id: number;
    report_date: string;
    period_from: string;
    period_to: string;
    merchant: string;
    mid: string;
    currency: string;
    sales_count: number;
    turnover: string;
    refunds: string;
    fees: string;
    reserve: string;
    net_payout: string;
    files: { pdf: boolean; xlsx: boolean; operations: boolean };
};

export type PortalSettlement = {
    id: number;
    number: string;
    merchant: string;
    status: 'approved' | 'settled';
    status_label: string;
    total_payout: string;
    payout_currency: string;
    wallet: string | null;
    approved_at: string | null;
    settled_at: string | null;
    tx_hash: string | null;
    explorer_url: string | null;
};

const ALL = 'all';

/**
 * Shown when the company has more than one merchant.
 */
export function MerchantSwitcher({
    shared,
    url,
    extra = {},
}: {
    shared: PortalShared;
    url: string;
    extra?: Record<string, string | null>;
}) {
    if (shared.merchants.length < 2) {
        return null;
    }

    return (
        <Select
            value={shared.merchant ?? ALL}
            onValueChange={(value) =>
                router.get(
                    url,
                    Object.fromEntries(
                        Object.entries({
                            ...extra,
                            merchant: value === ALL ? null : value,
                        }).filter(([, v]) => v),
                    ),
                    { preserveScroll: true, replace: true },
                )
            }
        >
            <SelectTrigger className="w-full bg-background sm:w-64">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>All merchants</SelectItem>
                {shared.merchants.map((m) => (
                    <SelectItem key={m.public_id} value={m.public_id}>
                        {m.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** One line per currency; a dash when there is nothing. */
export function Amounts({
    amounts,
    className,
}: {
    amounts: Amount[];
    className?: string;
}) {
    if (amounts.length === 0) {
        return <span className={className}>—</span>;
    }

    return (
        <span className={className}>
            {amounts.map((a) => (
                <span key={a.currency} className="block">
                    {formatMoney(a.amount, a.currency)}
                </span>
            ))}
        </span>
    );
}

export function ReportFiles({ report }: { report: PortalReport }) {
    const files = [
        ['pdf', 'PDF'],
        ['xlsx', 'XLSX'],
        ['operations', 'CSV'],
    ] as const;

    return (
        <div className="flex justify-end gap-1">
            {files.map(
                ([key, label]) =>
                    report.files[key] && (
                        <Button
                            key={key}
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs"
                            asChild
                        >
                            <a
                                href={portal.reports.download.url({
                                    report: report.id,
                                    file: key,
                                })}
                            >
                                <Download className="size-3.5" />
                                {label}
                            </a>
                        </Button>
                    ),
            )}
        </div>
    );
}

export function TxLink({ settlement }: { settlement: PortalSettlement }) {
    if (!settlement.tx_hash) {
        return <span className="text-muted-foreground">—</span>;
    }
    const short = `${settlement.tx_hash.slice(0, 8)}…${settlement.tx_hash.slice(-6)}`;

    return settlement.explorer_url ? (
        <a
            href={settlement.explorer_url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 font-mono text-xs text-brand hover:underline"
        >
            {short}
            <ExternalLink className="size-3" />
        </a>
    ) : (
        <span className="font-mono text-xs">{short}</span>
    );
}
