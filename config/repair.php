<?php

return [
    // شماره‌هایی که با ورود پیامکی نقش ادمین می‌گیرند (با کاما جدا شوند)
    'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('REPAIR_ADMIN_PHONES', ''))))),

    // آدرس فرانت سامانهٔ تعمیرکار (برای لینک‌های پیامک و بازگشت از درگاه)
    'frontend_url' => rtrim((string) env('REPAIR_FRONTEND_URL', ''), '/'),

    'brand_name' => env('REPAIR_BRAND_NAME', 'تعمیرکار'),

    // مرچنت زرین‌پال اختصاصی (مثلاً omidtamir) — در تنظیمات ادمین هم قابل ذخیره است
    'zarinpal_merchant_id' => trim((string) env('REPAIR_ZARINPAL_MERCHANT_ID', '')),
    // کال‌بک درگاه؛ خالی = {frontend_url}/zarinpal-callback.html
    'zarinpal_callback_url' => rtrim((string) env('REPAIR_ZARINPAL_CALLBACK_URL', ''), '/'),

    // نقشهٔ نشان: کلید web برای نمایش نقشه در مرورگر، کلید service برای تبدیل مختصات به آدرس (فقط سمت سرور)
    'neshan_map_key' => (string) env('NESHAN_MAP_KEY', ''),
    'neshan_service_key' => (string) env('NESHAN_SERVICE_KEY', ''),
];
