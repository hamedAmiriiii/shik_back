<?php

return [
    // شماره‌هایی که با ورود پیامکی نقش ادمین می‌گیرند (با کاما جدا شوند)
    'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('REPAIR_ADMIN_PHONES', ''))))),

    // آدرس فرانت سامانهٔ تعمیرکار (برای لینک‌های پیامک و بازگشت از درگاه)
    'frontend_url' => rtrim((string) env('REPAIR_FRONTEND_URL', ''), '/'),

    'brand_name' => env('REPAIR_BRAND_NAME', 'تعمیرکار'),
];
