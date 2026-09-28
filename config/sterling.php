<?php

return [

    /*
    | Reporting currency for cross-currency totals (dashboards, profit share).
    | Daily reports keep MID-currency amounts and freeze the rate used.
    */
    'base_currency' => env('STERLING_BASE_CURRENCY', 'EUR'),

    /*
    | Clearing trn_type codes. Chargeback codes are still to be confirmed
    | from a real Cardaq report.
    */
    'trn_types' => [
        'refund' => ['25', '1'],
        'chargeback' => array_filter(explode(',', (string) env('STERLING_CHARGEBACK_TRN_TYPES', ''))),
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

];
