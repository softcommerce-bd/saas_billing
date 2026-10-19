<?php

return [
    // Payment gateway driver for subscription payments: "bkash" or "simulator" (local/testing only).
    'gateway' => env('SUBSCRIPTION_PAYMENT_GATEWAY', 'bkash'),

    // Free trial granted at registration. 0 disables trials (tenant starts read-only).
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),

    // Payment attempts left in "initiated"/"unknown" are re-queried by the reconciler.
    'reconcile' => [
        'min_age_minutes' => 3,
        // After this window an unresolved checkout is treated as abandoned (expired).
        'abandon_after_hours' => 24,
        'max_attempts' => 30,
    ],

    // Days before (negative) / after (positive) expiry on which reminders are sent.
    'reminders' => [
        'expiry_7d' => -7,
        'expiry_3d' => -3,
        'expiry_1d' => -1,
        'expired' => 0,
        'expired_3d' => 3,
    ],

    // Maximum length of a single platform write-access override.
    'override_max_days' => 7,
];
