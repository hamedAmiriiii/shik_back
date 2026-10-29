<?php

return [
    'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('BILLIARD_ADMIN_PHONES', ''))))),

    'frontend_url' => rtrim((string) env('BILLIARD_FRONTEND_URL', 'https://billiard.webinoplus.ir'), '/'),

    'brand_name' => env('BILLIARD_BRAND_NAME', 'هیأت بیلیارد'),

    // تعداد تورنمنت‌های اخیر برای محاسبهٔ رنک (قابل override از جدول settings)
    'ranking_last_tournaments' => (int) env('BILLIARD_RANKING_LAST_TOURNAMENTS', 5),
];
