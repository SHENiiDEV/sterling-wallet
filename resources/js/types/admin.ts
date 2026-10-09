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
    cost_wallet_percent: string;
    cost_settlement_fx_percent: string;
    settlement_fee: string;
    settlement_cycle: string | null;
    min_settlement: string;
    rolling_reserve_percent: string;
    rolling_reserve_days: number;
    rolling_reserve_cap: string;
    notes: string | null;
    report_format: string | null;
    connector: string | null;
    timezone: string;
    report_delay_days: number;
    matching: MatchingRules | null;
    mids_count?: number;
    merchants_count?: number;
};

export type MatchingRules = {
    keys?: string[][];
    window_minutes?: number;
    try_timezone_shift?: boolean;
    tie_breakers?: string[];
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
    gate_mid: string | null;
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
    website: string | null;
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
    fee_collab_fixed: string | null;
    fee_fiat_to_crypto_percent: string;
    fee_wallet_percent: string;
    fee_settlement_fx_percent: string;
    fee_settlement_fixed: string;
    min_settlement_amount: string | null;
    rolling_reserve_cap: string | null;
    settlement_terms: string | null;
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

export type OperationType = 'sale' | 'refund' | 'decline' | 'chargeback';

export type Operation = {
    id: number;
    provider?: { id: number; name: string };
    role: 'bank' | 'gate';
    mid: string | null;
    merchant?: { public_id: string; name: string } | null;
    payment_id: string | null;
    sp_id: string | null;
    matched_operation_id: number | null;
    arn: string | null;
    rrn: string | null;
    approval_code: string | null;
    card_bin: string | null;
    card_last4: string | null;
    customer_email: string | null;
    ips: string | null;
    region: 'eu' | 'non_eu' | null;
    issuer_country: string | null;
    issuer_name: string | null;
    trn_type: string | null;
    operation_type: OperationType;
    operation_type_label: string;
    resolution: string | null;
    processing_code: string | null;
    amount: string;
    currency: string;
    report_date: string | null;
    transaction_at: string | null;
    processing_at: string | null;
};

export type OperationTotal = {
    currency: string;
    operation_type: OperationType;
    operations: number;
    amount: string;
};

export type BotRunStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'skipped';

export type BotRun = {
    id: number;
    connector: string;
    account: string | null;
    report_date: string;
    target_key: string;
    mids: string[];
    status: BotRunStatus;
    attempts: number;
    rows_count: number | null;
    files: string[];
    error: string | null;
    log: string | null;
    has_screenshot: boolean;
    has_live_frame: boolean;
    duration_ms: number | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type IntegrationAccount = {
    id: number;
    name: string;
    connector: string;
    provider: ProviderRef;
    login_url: string | null;
    has_username: boolean;
    has_password: boolean;
    has_totp: boolean;
    settings: Record<string, unknown> | null;
    mid_ids: number[];
    is_active: boolean;
    last_run: BotRun | null;
    failures_in_row: number;
};

export type ProfitSummary = {
    turnover: number;
    revenue: number;
    cost: number;
    net_profit: number;
    margin: number | null;
    reports: number;
    sales: number;
};

export type ProfitPoint = {
    date: string;
    turnover: number;
    net_profit: number;
};

export type ProfitByCurrency = {
    currency: string;
    turnover: number;
    net_profit: number;
    turnover_base: number;
    net_profit_base: number;
    reports: number;
};

export type ProfitByPair = {
    pair: string;
    turnover: number;
    revenue: number;
    cost: number;
    net_profit: number;
    margin: number | null;
    mids: number;
};

export type ProfitByMerchant = {
    public_id: string;
    name: string;
    turnover: number;
    revenue: number;
    net_profit: number;
    margin: number | null;
    reports: number;
};

export type ProfitOperations = {
    reports: Partial<
        Record<'pending' | 'partial' | 'blocked' | 'failed', number>
    >;
    settlements: { status: string; count: number; total: number }[];
    reserves: { currency: string; balance: number }[];
};

export type Period = { from: string; to: string };

export type DocumentTemplate = {
    id: number;
    name: string;
    description: string | null;
    original_name: string;
    size: number;
    uploader: string | null;
    updated_at: string;
};
