<?php

return [
    // اگر کلید در .env خالی باشد، env() مقدار پیش‌فرض را برنمی‌گرداند.
    'merchant_id' => env('ZARINPAL_MERCHANT_ID') ?: '28c5b546-aa10-482c-b916-463ff0d798dc',
    'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
    // دامنهٔ ثبت‌شده در پنل زرین‌پال: webinoo-plus.ir
    'callback_url' => env('ZARINPAL_CALLBACK_URL') ?: 'https://webinoo-plus.ir/zarinpal-callback.html',
    'frontend_return_url' => env('ZARINPAL_FRONTEND_RETURN_URL') ?: 'https://webinoo-plus.ir',
    'allowed_return_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ZARINPAL_RETURN_HOSTS',
        'webinoplus.ir,www.webinoplus.ir,webinoo-plus.ir,www.webinoo-plus.ir,api.webinoplus.ir,localhost'
    ))))),
];
