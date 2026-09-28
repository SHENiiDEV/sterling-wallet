export type StatusColor =
    | 'slate'
    | 'blue'
    | 'cyan'
    | 'emerald'
    | 'amber'
    | 'orange'
    | 'rose'
    | 'violet';

export type DocumentStatus = {
    id: number;
    name: string;
    color: StatusColor;
    sort_order: number;
    is_default: boolean;
    is_final: boolean;
    documents_count?: number;
};

export type Option = { value: string; label: string };

export type StaffMember = { id: number; name: string };

export type DocumentItem = {
    id: number;
    title: string;
    type: string;
    type_label: string;
    counterparty: string | null;
    notes: string | null;
    due_date: string | null;
    is_overdue: boolean;
    status: DocumentStatus;
    owner: StaffMember | null;
    files_count?: number;
    status_changed_at: string | null;
    created_at: string;
    updated_at: string;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};
