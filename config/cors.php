<?php

/**
 * دامنه‌های اصلی برند — همیشه در allowlist می‌مانند
 * (حتی اگر CORS_ALLOWED_ORIGINS در .env ناقص باشد).
 */
$brandOrigins = [
    'https://webinoo-plus.ir',
    'https://www.webinoo-plus.ir',
    'https://webino-plus.ir',
    'https://www.webino-plus.ir',
    'https://webinoplus.ir',
    'https://www.webinoplus.ir',
];

$brandPatterns = [
    '#^https://([a-z0-9-]+\.)?webinoo-plus\.ir$#i',
    '#^https://([a-z0-9-]+\.)?webino-plus\.ir$#i',
    '#^https://([a-z0-9-]+\.)?webinooplus\.ir$#i',
    '#^https://([a-z0-9-]+\.)?omidtamir\.ir$#i',
    '#^http://localhost(:\d+)?$#i',
    '#^http://127\.0\.0\.1(:\d+)?$#i',
];

$mergeUnique = static function (array $base, array $extra): array {
    foreach ($extra as $item) {
        $item = trim((string) $item);
        if ($item !== '' && ! in_array($item, $base, true)) {
            $base[] = $item;
        }
    }

    return $base;
};

$rawOrigins = env('CORS_ALLOWED_ORIGINS');

if ($rawOrigins !== null && trim((string) $rawOrigins) === '*') {
    $allowedOrigins = ['*'];
} else {
    $allowedOrigins = $brandOrigins;

    if ($rawOrigins !== null && trim((string) $rawOrigins) !== '') {
        $allowedOrigins = $mergeUnique(
            $allowedOrigins,
            array_map('trim', explode(',', (string) $rawOrigins))
        );
    } else {
        $allowedOrigins = $mergeUnique($allowedOrigins, [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
        ]);
    }

    $extraHosts = array_filter(array_map('trim', explode(',', (string) env(
        'CORS_EXTRA_ORIGINS',
        'https://omidtamir.ir,https://www.omidtamir.ir'
    ))));
    $allowedOrigins = $mergeUnique($allowedOrigins, $extraHosts);

    $repairUrl = rtrim((string) env('REPAIR_FRONTEND_URL', ''), '/');
    if ($repairUrl !== '') {
        $allowedOrigins = $mergeUnique($allowedOrigins, [$repairUrl]);
    }

    $billiardUrl = rtrim((string) env('BILLIARD_FRONTEND_URL', 'https://billiard.webinoplus.ir'), '/');
    if ($billiardUrl !== '') {
        $allowedOrigins = $mergeUnique($allowedOrigins, [$billiardUrl]);
    }
}

$rawPatterns = env('CORS_ALLOWED_ORIGIN_PATTERNS');
if ($rawPatterns !== null && trim((string) $rawPatterns) === '') {
    // صریحاً خالی = بدون الگو (فقط لیست origins)
    $allowedPatterns = [];
} elseif ($rawPatterns !== null) {
    $allowedPatterns = $mergeUnique(
        $brandPatterns,
        array_map('trim', explode(',', (string) $rawPatterns))
    );
} else {
    $allowedPatterns = $brandPatterns;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | دامنه‌های webinoo-plus.ir و webinoplus.ir همیشه مجازند.
    | CORS_ALLOWED_ORIGINS=* = باز کامل
    | CORS_ALLOWED_ORIGINS=https://... = لیست اضافه بر برند
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
