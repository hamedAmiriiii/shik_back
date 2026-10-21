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
        // code | shop_code
        // الگوی ۵۶۴۷۰۳: {0} کد تایید هویت شما : {1} webinoo-plus
        'otp_vars_mode' => env('MELIPAYAMAK_OTP_VARS_MODE', 'shop_code'),
    ],
];
