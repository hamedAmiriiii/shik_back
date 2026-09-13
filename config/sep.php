<?php

return [
    'terminal_id' => env('SEP_TERMINAL_ID') ?: '15769040',
    // باید دقیقاً همان آدرس ثبت‌شده در پنل SEP باشد
    'callback_url' => env('SEP_CALLBACK_URL') ?: 'https://webinoo-plus.ir/pay',
    'token_url' => env('SEP_TOKEN_URL') ?: 'https://sep.shaparak.ir/onlinepg/onlinepg',
    'pay_url' => env('SEP_PAY_URL') ?: 'https://sep.shaparak.ir/OnlinePG/OnlinePG',
    'verify_url' => env('SEP_VERIFY_URL') ?: 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction',
];
