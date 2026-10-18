<?php

$rawOrigins = env('CORS_ALLOWED_ORIGINS');

if ($rawOrigins === null || trim((string) $rawOrigins) === '') {
    // پیش‌فرض دامنه‌های وبینو + لوکال؛ با CORS_ALLOWED_ORIGINS در .env قابل جایگزینی است
    $allowedOrigins = [
        'https://webinoo-plus.ir',
        'https://www.webinoo-plus.ir',
        'https://webinooplus.ir',
        'https://www.webinooplus.ir',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ];

    $extraHosts = array_filter(array_map('trim', explode(',', (string) env(
        'CORS_EXTRA_ORIGINS',
        'https://omidtamir.ir,https://www.omidtamir.ir'
    ))));
    foreach ($extraHosts as $origin) {
        if ($origin !== '' && ! in_array($origin, $allowedOrigins, true)) {
            $allowedOrigins[] = $origin;
        }
    }

    $repairUrl = rtrim((string) env('REPAIR_FRONTEND_URL', ''), '/');
    if ($repairUrl !== '' && ! in_array($repairUrl, $allowedOrigins, true)) {
        $allowedOrigins[] = $repairUrl;
    }
} elseif (trim((string) $rawOrigins) === '*') {
    $allowedOrigins = ['*'];
} else {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string) $rawOrigins))));
}

$rawPatterns = env('CORS_ALLOWED_ORIGIN_PATTERNS');
if ($rawPatterns === null) {
    $allowedPatterns = [
        '#^https://([a-z0-9-]+\.)?webinoo-plus\.ir$#i',
        '#^https://([a-z0-9-]+\.)?webinooplus\.ir$#i',
        '#^https://([a-z0-9-]+\.)?omidtamir\.ir$#i',
        '#^http://localhost(:\d+)?$#i',
        '#^http://127\.0\.0\.1(:\d+)?$#i',
    ];
} elseif (trim((string) $rawPatterns) === '') {
    $allowedPatterns = [];
} else {
    $allowedPatterns = array_values(array_filter(array_map('trim', explode(',', (string) $rawPatterns))));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | origins از CORS_ALLOWED_ORIGINS (و الگوها از CORS_ALLOWED_ORIGIN_PATTERNS).
    | برای باز کردن کامل موقت: CORS_ALLOWED_ORIGINS=*
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedPatterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 60 * 60,

    'supports_credentials' => false,

];
