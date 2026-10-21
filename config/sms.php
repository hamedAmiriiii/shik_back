<?php

return [
    /*
    |--------------------------------------------------------------------------
    | پیش‌فرض پروایدر (اگر در تنظیمات ادمین چیزی ذخیره نشده باشد)
    | shina | melipayamak
    |--------------------------------------------------------------------------
    */
    'default_provider' => env('SMS_PROVIDER', 'shina'),

    'shina' => [
        'api_token' => env('SHINA_SMS_API_TOKEN', 'sa345478720:L2ZSDrZ7lEYzxark92MWhH2mveIUxQ7KABqI'),
        'gateway' => env('SHINA_SMS_GATEWAY', '10003000207'),
        'base_url' => env('SHINA_SMS_BASE_URL', 'http://api.shinapayamak.ir/v1'),
        'log_sender' => env('SHINA_SMS_LOG_SENDER', '1000528554'),
    ],

    'melipayamak' => [
        'username' => env('MELIPAYAMAK_USERNAME', ''),
        'api_key' => env('MELIPAYAMAK_API_KEY', ''),
        'from' => env('MELIPAYAMAK_FROM', '30008810852036'),
        'base_url' => env('MELIPAYAMAK_BASE_URL', 'https://rest.payamak-panel.com/api/SendSMS'),
        'otp_body_id' => env('MELIPAYAMAK_OTP_BODY_ID', '564703'),
        // الگوی باشگاه ۵۶۴۷۷۵: {0} {1}عزیز مبلغ {2} تومان به اعتبار شما برای خرید بعدی اضافه شد webinoo-plus.ir
        'credit_body_id' => env('MELIPAYAMAK_CREDIT_BODY_ID', '564775'),
        // هشدار کمبود اعتبار فروشگاه — بعد از تأیید در ملی‌پیامک شناسه را بگذارید:
        // {0}\nاعتبار ارسال پیامک فروشگاه شما رو به اتمام است. موجودی باقیمانده: {1} پیامک\nwebinoo-plus
        'low_quota_body_id' => env('MELIPAYAMAK_LOW_QUOTA_BODY_ID', ''),
        // code | shop_code
        // الگوی ۵۶۴۷۰۳: {0} کد تایید هویت شما : {1} webinoo-plus
        'otp_vars_mode' => env('MELIPAYAMAK_OTP_VARS_MODE', 'shop_code'),
    ],
];
