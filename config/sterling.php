<?php

return [

    /*
    | Reporting currency for cross-currency totals (dashboards, profit share).
    | Daily reports keep MID-currency amounts and freeze the rate used.
    */
    'base_currency' => env('STERLING_BASE_CURRENCY', 'EUR'),

    /*
    | Clearing trn_type codes, compared case-insensitively (write them in
    | lower case). Chargebacks are not classified yet: only Cardaq logic
    | exists and its chargeback codes are still to be confirmed.
    */
    'trn_types' => [
        'refund' => ['25', '1', 'refund'],
        'chargeback' => array_values(array_filter(array_map('strtolower', explode(',', (string) env('STERLING_CHARGEBACK_TRN_TYPES', ''))))),
    ],

    /*
    | Gateway resolutions that mean the payment went through.
    */
    'successful_resolutions' => ['ok', 'approved', 'success', 'successful'],

    /*
    | Defaults for merchants created from an unknown MID (status: review).
    */
    'merchant_defaults' => [
        'rolling_reserve_percent' => 10,
        'rolling_reserve_days' => 180,
        'fee_fiat_to_crypto_percent' => 0.4,
    ],

    /*
    | Time zone of operation times in provider files, unless the provider
    | overrides it. Parsers convert to UTC with it.
    */
    'timezone' => env('STERLING_REPORT_TIMEZONE', 'Europe/Riga'),

    /*
    | Default reconciliation rules; a provider's `matching` JSON overrides
    | any of these keys. Keys are tried in order, strictest first.
    */
    'matching' => [
        'keys' => [
            ['card_bin', 'card_last4', 'amount', 'currency'],
            ['card_bin', 'amount', 'currency'],
        ],
        'window_minutes' => 5,
        'try_timezone_shift' => true,
        'tie_breakers' => ['email', 'closest_time'],
    ],

    /*
    | Issuer countries counted as EU when a report has no region column.
    */
    'eu_countries' => [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
    ],

    /*
    | Report files: where they land and how far back bots look for gaps.
    */
    'reports' => [
        'disk' => env('STERLING_REPORTS_DISK', 'local'),
        'pending_lookback_days' => (int) env('STERLING_PENDING_LOOKBACK_DAYS', 14),
        'reconcile_lookback_days' => (int) env('STERLING_RECONCILE_LOOKBACK_DAYS', 30),
    ],

    /*
    | Bearer token the external report bot sends (BOT_REPORTS_API_KEY).
    */
    'bot_api_key' => env('BOT_REPORTS_API_KEY'),

    /*
    | Built-in bots: Playwright scripts in bots/{connector}.mjs, run by Laravel.
    */
    'bots' => [
        'node_binary' => env('BOTS_NODE_BINARY', 'node'),
        'scripts_path' => base_path('bots'),
        // Where Playwright keeps Chromium: inside the project, so it does not
        // depend on which user (root, www-data) installed it. Fill it with
        // `cd bots && npm run install-browser`.
        'browsers_path' => env('BOTS_BROWSERS_PATH', base_path('bots/.browsers')),
        'timeout_seconds' => (int) env('BOTS_TIMEOUT_SECONDS', 900),
        'headless' => (bool) env('BOTS_HEADLESS', true),
        'proxy' => env('BOTS_HTTP_PROXY'),
        'alert_after_failures' => 3,
        'telegram_token' => env('TELEGRAM_TOKEN'),
        'telegram_chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    /*
    | Column names per report format. Headers are compared case-, space- and
    | punctuation-insensitively; the first alias found in the file wins.
    | Adjust here when a provider renames a column — no code change needed.
    */
    'parsers' => [
        'cardaq' => [
            'mid' => ['mid', 'merchant id', 'merchant number', 'merchant no'],
            'provider_login' => ['login', 'provider login', 'user'],
            'merchant_name' => ['merchant name', 'merchant', 'dba name', 'company', 'company name'],
            'payment_id' => ['transaction id', 'trn id', 'trx id', 'order id', 'payment id', 'id'],
            'arn' => ['arn', 'acquirer reference number'],
            'rrn' => ['rrn', 'retrieval reference number'],
            'approval_code' => ['approval code', 'auth code', 'authorization code'],
            'card' => ['card', 'card number', 'pan', 'card mask', 'masked pan'],
            'card_bin' => ['bin', 'card bin'],
            'card_last4' => ['last4', 'last 4', 'card last 4'],
            'ips' => ['ips', 'card brand', 'brand', 'scheme', 'card type', 'payment system'],
            'region' => ['region', 'area', 'eu non eu', 'eu/non-eu'],
            'issuer_country' => ['issuer country', 'bin country', 'card country', 'country'],
            'issuer_name' => ['issuer', 'issuer name', 'issuer bank', 'bank'],
            'amount' => ['amount', 'trn amount', 'transaction amount', 'tr amount'],
            'currency' => ['currency', 'trn currency', 'transaction currency', 'ccy'],
            'trn_type' => ['trn type', 'transaction type', 'tr type', 'type'],
            'processing_code' => ['processing code', 'proc code'],
            'resolution' => ['resolution', 'response code', 'result'],
            'transacted_at' => ['trn date', 'transaction date', 'trn date time', 'transaction datetime', 'date'],
            'processed_at' => ['processing date', 'proc date', 'settlement date', 'posting date'],
            'eu_fee' => ['eu fee'],
            'non_eu_fee' => ['non eu fee'],
            'ic_fee' => ['ic fee', 'ic++ fee'],
            'ic_interchange' => ['interchange', 'ic interchange'],
            'ic_scheme_fee' => ['scheme fee', 'ic scheme fee'],
            'approve_fee' => ['approve fee', 'approval fee'],
            'decline_fee' => ['decline fee'],
            'refund_fee' => ['refund fee'],
        ],
        'corefy' => [
            'mid' => ['commerce account', 'commerce account id', 'account id', 'mid'],
            'merchant_name' => ['commerce account name', 'merchant', 'merchant name'],
            'payment_id' => ['id', 'invoice id', 'payment invoice id', 'payment id'],
            'reference_id' => ['reference id', 'reference', 'order id'],
            'arn' => ['arn'],
            'rrn' => ['rrn'],
            'approval_code' => ['auth code', 'approval code'],
            'card' => ['card', 'card number', 'masked card', 'pan', 'account', 'payer account'],
            'card_bin' => ['bin', 'card bin'],
            'card_last4' => ['last4', 'card last 4'],
            'ips' => ['card brand', 'brand', 'payment method', 'card type'],
            'issuer_country' => ['card country', 'issuer country', 'bin country'],
            'issuer_name' => ['issuer', 'card issuer', 'bank'],
            'email' => ['email', 'customer email', 'payer email', 'customer'],
            'amount' => ['amount', 'payment amount', 'total amount'],
            'currency' => ['currency'],
            'trn_type' => ['type', 'operation type', 'transaction type'],
            'resolution' => ['resolution', 'result', 'decline reason'],
            'processing_code' => ['status', 'processing code', 'status code'],
            'transacted_at' => ['created', 'created at', 'created_at', 'date', 'processed', 'processed at'],
        ],
        'madfin' => [
            'mid' => ['mid', 'merchant id', 'terminal id'],
            'merchant_name' => ['merchant name', 'merchant'],
            'payment_id' => ['transaction id', 'id', 'payment id'],
            'arn' => ['arn'],
            'rrn' => ['rrn'],
            'approval_code' => ['auth code', 'approval code'],
            'card' => ['card', 'card number', 'pan', 'masked pan'],
            'card_bin' => ['bin'],
            'card_last4' => ['last4', 'last 4'],
            'ips' => ['card brand', 'scheme', 'brand', 'card type'],
            'region' => ['region'],
            'issuer_country' => ['issuer country', 'card country', 'country'],
            'amount' => ['amount', 'transaction amount'],
            'currency' => ['currency'],
            'trn_type' => ['transaction type', 'type', 'trn type'],
            'resolution' => ['status', 'result'],
            'transacted_at' => ['transaction date', 'date', 'created'],
            'processed_at' => ['settlement date', 'processing date'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Commercial proposal (offer PDF)
    |--------------------------------------------------------------------------
    |
    | Company texts printed on every proposal. Change them here; the rates
    | and extra charges come from each offer.
    |
    */

    'proposal' => [
        'headline' => 'Card acquiring with crypto settlement',
        'about' => 'Sterling Pay connects your business to licensed acquiring banks across Europe and settles your card revenue in USDC. One integration, daily reporting and fast, transparent payouts — with a dedicated team that knows high-growth e-commerce.',
        'services' => [
            ['Card acquiring', 'Visa and Mastercard processing through tier-one European acquiring banks.'],
            ['Multi-currency', 'Accept payments in EUR, GBP and USD on dedicated MIDs.'],
            ['Crypto settlement', 'Payouts in USDC to your own wallet, T+2 after each business day.'],
            ['Daily reporting', 'A detailed statement for every day: sales, fees, reserve and payout.'],
            ['Risk & reserve', 'Transparent rolling reserve, released automatically when due.'],
            ['Dedicated support', 'A named account manager from onboarding to scale.'],
        ],
        'highlights' => ['Licensed acquiring partners', 'Daily USDC settlement', 'Transparent pricing'],
        'contact' => [
            'email' => env('PROPOSAL_CONTACT_EMAIL', 'sales@sterling-pay.com'),
            'website' => env('PROPOSAL_WEBSITE', 'sterling-pay.com'),
        ],
    ],

];
