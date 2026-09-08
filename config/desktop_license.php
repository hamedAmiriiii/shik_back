<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Desktop license (Webinoo Desktop annual subscription)
    |--------------------------------------------------------------------------
    */
    'enabled' => filter_var(env('DESKTOP_LICENSE_ENFORCE', true), FILTER_VALIDATE_BOOLEAN),

    /** Offline grace after last successful online validation (days). */
    'offline_grace_days' => (int) env('DESKTOP_LICENSE_GRACE_DAYS', 10),

    /** Default subscription length when creating licenses. */
    'subscription_years' => (int) env('DESKTOP_LICENSE_YEARS', 1),

    /**
     * Private key PEM for signing (license server / artisan create).
     * Prefer env DESKTOP_LICENSE_PRIVATE_KEY (full PEM) or path.
     */
    'private_key' => env('DESKTOP_LICENSE_PRIVATE_KEY'),
    'private_key_path' => env(
        'DESKTOP_LICENSE_PRIVATE_KEY_PATH',
        base_path('../webinoo-desktop/config/keys/desktop_license.private.pem')
    ),

    /** Public key PEM for verification (shipped with desktop + cloud). */
    'public_key' => env('DESKTOP_LICENSE_PUBLIC_KEY'),
    'public_key_path' => env(
        'DESKTOP_LICENSE_PUBLIC_KEY_PATH',
        config_path('desktop_license.public.pem')
    ),

    /** Path to local license.json written by Electron (desktop mode). */
    'license_file' => env('DESKTOP_LICENSE_FILE'),

    /** Dev bypass — never enable in production builds. */
    'bypass' => filter_var(env('WEBINOO_LICENSE_BYPASS', false), FILTER_VALIDATE_BOOLEAN),
];
