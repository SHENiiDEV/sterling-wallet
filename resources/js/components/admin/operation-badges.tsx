import { StatusBadge } from '@/components/admin/status-badge';
import type { BotRunStatus, OperationType, StatusColor } from '@/types';

const operationColors: Record<OperationType, StatusColor> = {
    sale: 'emerald',
    refund: 'blue',
    decline: 'slate',
    chargeback: 'rose',
};

export function OperationTypeBadge({
    type,
    label,
}: {
    type: OperationType;
    label: string;
}) {
    return <StatusBadge name={label} color={operationColors[type]} />;
}

const runColors: Record<BotRunStatus, StatusColor> = {
    queued: 'slate',
    running: 'blue',
    succeeded: 'emerald',
    failed: 'rose',
    skipped: 'amber',
};

export function BotRunStatusBadge({ status }: { status: BotRunStatus }) {
    return (
        <StatusBadge
            name={status.charAt(0).toUpperCase() + status.slice(1)}
            color={runColors[status]}
        />
    );
}

export function RoleLabel({ role }: { role: 'bank' | 'gate' }) {
    return (
        <span className="text-xs text-muted-foreground">
            {role === 'bank' ? 'Acquirer' : 'Gateway'}
        </span>
    );
}

export function CardLabel({
    bin,
    last4,
}: {
    bin: string | null;
    last4: string | null;
}) {
    if (!bin && !last4) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className="font-mono text-xs">
            {bin ?? '······'}
            <span className="text-muted-foreground">••</span>
            {last4 ?? '····'}
        </span>
    );
}
