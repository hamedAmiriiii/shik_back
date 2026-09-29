<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'moadian' => [
        'production_url' => env('MOADIAN_PRODUCTION_URL', 'https://tp.tax.gov.ir/requestsmanager/api/v2'),
        'sandbox_url' => env('MOADIAN_SANDBOX_URL', 'https://sandboxrc.tax.gov.ir/requestsmanager/api/v2'),
        'timeout' => (int) env('MOADIAN_TIMEOUT', 30),
        'batch_size' => (int) env('MOADIAN_BATCH_SIZE', 20),
        'log_retention_days' => (int) env('MOADIAN_LOG_RETENTION_DAYS', 90),
    ],

];
