import type { StatusColor } from '@/types';

export type SettlementItem = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    merchant: { public_id: string; name: string };
    payout_currency: string;
    total_payout: string;
    lines_count: number;
    created_at: string | null;
    settled_at: string | null;
    tx_hash: string | null;
};

export const settlementColors: Record<string, StatusColor> = {
    draft: 'slate',
    approved: 'blue',
    settled: 'emerald',
    cancelled: 'rose',
};
