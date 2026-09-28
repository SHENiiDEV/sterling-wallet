import type { StatusColor } from '@/types';

export type OfferItem = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    company_name: string;
    contact_email: string | null;
    currencies: string[];
    valid_until: string | null;
    merchant: { public_id: string; name: string } | null;
    creator: string | null;
    created_at: string | null;
    sent_at: string | null;
};

export const offerColors: Record<string, StatusColor> = {
    draft: 'slate',
    sent: 'blue',
    accepted: 'emerald',
    declined: 'rose',
    expired: 'amber',
};
