<?php

/*
 * bKash Tokenized Checkout configuration for the PLATFORM's merchant account
 * (SaaS subscription payments only).
 *
 * IMPORTANT: The default paths below follow the publicly described bKash Tokenized
 * Checkout API (v1.2.0-beta). They have NOT been verified against the official
 * documentation for this merchant account. Before enabling production, confirm the
 * base URL, every path, header and field name against the documentation bKash
 * provides with the merchant credentials, and record the confirmation in
 * docs/subscriptions/bkash-integration.md.
 */
return [
    'enabled' => (bool) env('BKASH_ENABLED', false),

    // "sandbox" or "production". Stored on each payment record.
    'environment' => env('BKASH_ENVIRONMENT', 'sandbox'),

    // Example sandbox base: https://tokenized.sandbox.bka.sh/v1.2.0-beta (verify).
    'base_url' => rtrim((string) env('BKASH_BASE_URL', ''), '/'),

    'app_key' => env('BKASH_APP_KEY'),
    'app_secret' => env('BKASH_APP_SECRET'),
    'username' => env('BKASH_USERNAME'),
    'password' => env('BKASH_PASSWORD'),

    'timeout_seconds' => (int) env('BKASH_TIMEOUT_SECONDS', 30),

    'paths' => [
        'grant_token' => env('BKASH_PATH_GRANT_TOKEN', '/tokenized/checkout/token/grant'),
        'refresh_token' => env('BKASH_PATH_REFRESH_TOKEN', '/tokenized/checkout/token/refresh'),
        'create_payment' => env('BKASH_PATH_CREATE', '/tokenized/checkout/create'),
        'execute_payment' => env('BKASH_PATH_EXECUTE', '/tokenized/checkout/execute'),
        'query_payment' => env('BKASH_PATH_QUERY', '/tokenized/checkout/payment/status'),
        'refund_payment' => env('BKASH_PATH_REFUND', '/tokenized/checkout/payment/refund'),
    ],

    // Payment "mode" for one-off customer-initiated checkout without agreement (verify).
    'checkout_mode' => env('BKASH_CHECKOUT_MODE', '0011'),

    // Gateway status code that signals success in API responses (verify).
    'success_status_code' => '0000',
];
