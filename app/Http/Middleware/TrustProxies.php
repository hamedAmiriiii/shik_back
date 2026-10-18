<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * از env: TRUSTED_PROXIES
     * - خالی: فقط REMOTE_ADDR (مناسب وقتی PHP مستقیم در معرض اینترنت است)
     * - *: همه پروکسی‌ها (وقتی فقط پشت nginx/Cloudflare در دسترس است)
     * - لیست IP با ویرگول: 127.0.0.1,::1,10.0.0.1
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $this->proxies = $this->resolveTrustedProxies();
    }

    /**
     * @return array<int, string>|string|null
     */
    protected function resolveTrustedProxies()
    {
        $raw = env('TRUSTED_PROXIES');

        if ($raw === null || trim((string) $raw) === '') {
            // پیش‌فرض امن برای استقرار پشت nginx روی همین ماشین
            return ['127.0.0.1', '::1'];
        }

        $value = trim((string) $raw);
        if ($value === '*' || $value === '**') {
            return '*';
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
