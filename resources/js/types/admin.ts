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
    company: { id: number; name: string } | null;
    merchant: { id: number; name: string; public_id: string } | null;
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

export type CompanyRef = { id: number; name: string };

export type Company = {
    id: number;
    name: string;
    parent_id: number | null;
    parent?: CompanyRef | null;
    registration_number: string | null;
    country: string | null;
    billing_details: string | null;
    notes: string | null;
    merchants_count?: number;
    children_count?: number;
};

export type ProviderType = 'bank' | 'gate' | 'crypto';

export type Provider = {
    id: number;
    name: string;
    code: string;
    type: ProviderType;
    type_label: string;
    is_active: boolean;
    cost_visa_eu_percent: string | null;
    cost_visa_non_eu_percent: string | null;
    cost_mastercard_eu_percent: string | null;
    cost_mastercard_non_eu_percent: string | null;
    cost_acq_eu_percent: string;
    cost_acq_non_eu_percent: string;
    cost_success_fixed: string;
    cost_decline_fixed: string;
    cost_refund_fixed: string;
    cost_chargeback_fixed: string;
    cost_crypto_percent: string;
    settlement_fee: string;
    settlement_cycle: string | null;
    min_settlement: string;
    rolling_reserve_percent: string;
    rolling_reserve_days: number;
    rolling_reserve_cap: string;
    notes: string | null;
    mids_count?: number;
    merchants_count?: number;
};

export type ProviderRef = { id: number; name: string; is_active?: boolean };

export type MerchantStatus =
    | 'onboarding'
    | 'review'
    | 'active'
    | 'suspended'
    | 'closed';

export type MidStatus = 'active' | 'inactive' | 'review';

export type CurrencyCode = 'USD' | 'EUR' | 'GBP';

export type Mid = {
    id: number;
    mid: string;
    provider_login: string | null;
    currency: CurrencyCode;
    label: string | null;
    status: MidStatus;
    status_label: string;
    bank_provider_id: number | null;
    gate_provider_id: number | null;
    bank_provider?: string | null;
    gate_provider?: string | null;
    reports_start_date: string | null;
    rolling_reserve_limit: string;
    processing_limit: string | null;
    reserve_balance?: string;
    notes: string | null;
};

export type Merchant = {
    id: number;
    public_id: string;
    name: string;
    status: MerchantStatus;
    status_label: string;
    is_test: boolean;
    company_id: number | null;
    company?: CompanyRef | null;
    crypto_provider_id: number | null;
    crypto_provider?: string | null;
    fee_visa_eu_percent: string | null;
    fee_visa_non_eu_percent: string | null;
    fee_mastercard_eu_percent: string | null;
    fee_mastercard_non_eu_percent: string | null;
    fee_acq_eu_percent: string;
    fee_acq_non_eu_percent: string;
    fee_success_fixed: string;
    fee_decline_fixed: string;
    fee_refund_fixed: string;
    fee_chargeback_fixed: string;
    fee_fiat_to_crypto_percent: string;
    rolling_reserve_percent: string;
    rolling_reserve_days: number;
    invoice_email: string | null;
    mcc: string | null;
    onboarding_status: string | null;
    notes: string | null;
    mids?: Mid[];
    missing_tariff: string[];
    created_at: string;
};

export type Wallet = {
    id: number;
    type: string;
    type_label: string;
    label: string | null;
    currency: string;
    network: string;
    address: string;
    has_seed: boolean;
    notes: string | null;
    is_active: boolean;
};

export type MerchantRef = {
    id: number;
    name: string;
    company_id: number | null;
};
