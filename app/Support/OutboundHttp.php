<?php

namespace App\Support;

class OutboundHttp
{
    /**
     * گزینه‌های SSL برای درخواست‌های خروجی (زرین‌پال، SEP، …).
     * روی ویندوز/هاست‌هایی که CA سیستم ناقص است، از storage/certs/cacert.pem استفاده می‌شود.
     *
     * @return array{verify: bool|string}
     */
    public static function sslOptions(): array
    {
        $flag = env('HTTP_SSL_VERIFY', true);
        if ($flag === false || $flag === 'false' || $flag === '0' || $flag === 0) {
            return ['verify' => false];
        }

        $bundle = trim((string) env('HTTP_CA_BUNDLE', ''));
        if ($bundle === '') {
            $bundle = storage_path('certs/cacert.pem');
        }
        if (is_file($bundle) && is_readable($bundle)) {
            return ['verify' => $bundle];
        }

        $ini = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($ini) && $ini !== '' && is_file($ini)) {
            return ['verify' => $ini];
        }

        return ['verify' => true];
    }
}
