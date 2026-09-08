<?php

return [
    'merchant_id' => env('ZARINPAL_MERCHANT_ID', '28c5b546-aa10-482c-b916-463ff0d798dc'),
    'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
    'callback_url' => env('ZARINPAL_CALLBACK_URL'),
    'frontend_return_url' => env('ZARINPAL_FRONTEND_RETURN_URL', env('APP_URL', 'https://api.webinoplus.ir')),
    'allowed_return_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ZARINPAL_RETURN_HOSTS',
        'webinoo-plus.ir,www.webinoo-plus.ir,api.webinoplus.ir,localhost'
    ))))),
];
