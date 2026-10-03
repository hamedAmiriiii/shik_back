<?php

namespace App\Services\GoogleSheets;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * کلاینت حداقلی Google Sheets API v4 با Service Account (JWT).
 */
class GoogleSheetsClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    /** aud توکن JWT همیشه آدرس اصلی گوگل است، حتی اگر از واسط استفاده شود. */
    private const TOKEN_AUDIENCE = 'https://oauth2.googleapis.com/token';

    private const MAX_RETRIES = 4;

    /** @var array<string, mixed>|null */
    private $credentials;

    /** @var Client|null */
    private $http;

    /** @var ShopGoogleOAuth|null */
    private $oauth;

    /** @var int|null */
    private $oauthAtelierId;

    /**
     * همین کلاینت، ولی با توکن حساب گوگلِ خود فروشگاه به‌جای Service Account.
     */
    public function forShopAccount(ShopGoogleOAuth $oauth, int $atelierId): self
    {
        $client = clone $this;
        $client->oauth = $oauth;
        $client->oauthAtelierId = $atelierId;

        return $client;
    }

    public function usesShopAccount(): bool
    {
        return $this->oauthAtelierId !== null;
    }

    /**
     * @return string شناسهٔ شیت ساخته‌شده
     */
    public function createSpreadsheet(string $title): string
    {
        $result = $this->request('POST', '/spreadsheets', [
            'json' => ['properties' => ['title' => $title]],
        ]);
        $id = (string) ($result['spreadsheetId'] ?? '');
        if ($id === '') {
            throw new GoogleSheetsException('ساخت گوگل شیت ممکن نشد.');
        }

        return $id;
    }

    public function isConfigured(): bool
    {
        try {
            $this->credentials();

            return true;
        } catch (GoogleSheetsException $e) {
            return false;
        }
    }

    public function serviceAccountEmail(): ?string
    {
        try {
            return (string) $this->credentials()['client_email'];
        } catch (GoogleSheetsException $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSpreadsheet(string $spreadsheetId): array
    {
        return $this->request('GET', '/spreadsheets/'.rawurlencode($spreadsheetId), [
            'query' => ['fields' => 'spreadsheetId,properties.title,sheets.properties'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    public function batchUpdate(string $spreadsheetId, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->request('POST', '/spreadsheets/'.rawurlencode($spreadsheetId).':batchUpdate', [
            'json' => ['requests' => $requests],
        ]);
    }

    /**
     * @param  array<int, string>  $ranges
     */
    public function batchClearValues(string $spreadsheetId, array $ranges): void
    {
        if ($ranges === []) {
            return;
        }
        $this->request('POST', '/spreadsheets/'.rawurlencode($spreadsheetId).'/values:batchClear', [
            'json' => ['ranges' => array_values($ranges)],
        ]);
    }

    /**
     * RAW: مقدارهایی که با = شروع می‌شوند فرمول تفسیر نمی‌شوند.
     *
     * @param  array<int, array{range: string, values: array<int, array<int, mixed>>}>  $data
     */
    public function batchUpdateValues(string $spreadsheetId, array $data): void
    {
        if ($data === []) {
            return;
        }
        $this->request('POST', '/spreadsheets/'.rawurlencode($spreadsheetId).'/values:batchUpdate', [
            'json' => [
                'valueInputOption' => 'RAW',
                'data' => array_values($data),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = rtrim((string) config('services.google_sheets.api_base'), '/').$path;
        $attempt = 0;

        while (true) {
            $attempt++;
            $options['headers'] = ['Authorization' => 'Bearer '.$this->currentAccessToken()];

            try {
                $response = $this->http()->request($method, $url, $options);
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            } catch (ConnectException $e) {
                throw new GoogleSheetsException('اتصال سرور به گوگل برقرار نشد. احتمالاً دسترسی سرور به Google API مسدود است.', 0, $e);
            } catch (RequestException $e) {
                $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                if ($status === 401 && $attempt === 1) {
                    if ($this->oauth !== null) {
                        $this->oauth->forgetAccessToken((int) $this->oauthAtelierId);
                    } else {
                        Cache::forget($this->tokenCacheKey());
                    }
                    continue;
                }
                if (($status === 429 || $status >= 500) && $attempt < self::MAX_RETRIES) {
                    sleep(min(2 ** $attempt, 20));
                    continue;
                }
                throw $this->translateError($e, $status);
            }
        }
    }

    private function translateError(RequestException $e, int $status): GoogleSheetsException
    {
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        $decoded = json_decode($body, true);
        $apiMessage = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';

        if ($status === 403) {
            $message = $this->oauth !== null
                ? 'حساب گوگل فروشگاه به این شیت دسترسی ندارد. قطع اتصال کنید و دوباره با گوگل وارد شوید.'
                : 'دسترسی به شیت داده نشده است. شیت را با ایمیل '.$this->serviceAccountEmail().' به صورت Editor به اشتراک بگذارید.';
            if (stripos($apiMessage, 'has not been used') !== false || stripos($apiMessage, 'disabled') !== false) {
                $message = 'Google Sheets API در پروژه گوگل فعال نیست.';
            } elseif (stripos($apiMessage, 'location') !== false || stripos($apiMessage, 'region') !== false) {
                $message = 'گوگل درخواست را به دلیل موقعیت جغرافیایی سرور رد کرد. از واسط (relay) استفاده کنید.';
            }

            return new GoogleSheetsException($message, $status, $e);
        }
        if ($status === 404) {
            return new GoogleSheetsException('شیت پیدا نشد. لینک شیت را بررسی کنید.', $status, $e);
        }
        if ($status === 429) {
            return new GoogleSheetsException('محدودیت تعداد درخواست گوگل پر شد. چند دقیقه بعد دوباره تلاش کنید.', $status, $e);
        }

        return new GoogleSheetsException(
            'خطای گوگل شیت'.($status ? ' ('.$status.')' : '').($apiMessage !== '' ? ': '.$apiMessage : ''),
            $status,
            $e
        );
    }

    private function currentAccessToken(): string
    {
        if ($this->oauth !== null) {
            return $this->oauth->accessToken((int) $this->oauthAtelierId);
        }

        return $this->accessToken();
    }

    private function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = $this->credentials();
        $now = time();
        $jwt = $this->signJwt([
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_AUDIENCE,
            'iat' => $now,
            'exp' => $now + 3600,
        ], (string) $creds['private_key']);

        try {
            $response = $this->http()->post((string) config('services.google_sheets.token_url'), [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);
        } catch (ConnectException $e) {
            throw new GoogleSheetsException('اتصال سرور به گوگل برقرار نشد. احتمالاً دسترسی سرور به Google API مسدود است.', 0, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw new GoogleSheetsException('دریافت توکن گوگل ناموفق بود'.($status ? ' ('.$status.')' : '').'. کلید Service Account را بررسی کنید.', $status, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
        if ($token === '') {
            throw new GoogleSheetsException('پاسخ توکن گوگل نامعتبر است.');
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 300);
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signJwt(array $claims, string $privateKey): string
    {
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new GoogleSheetsException('امضای توکن گوگل ممکن نشد. کلید خصوصی Service Account نامعتبر است.');
        }
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = (string) config('services.google_sheets.credentials_path');
        if ($path === '') {
            throw new GoogleSheetsException('اتصال گوگل شیت روی سرور تنظیم نشده است.');
        }
        if (! preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path)) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new GoogleSheetsException('اتصال گوگل شیت روی سرور تنظیم نشده است (فایل کلید Service Account پیدا نشد).');
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            throw new GoogleSheetsException('فایل کلید Service Account نامعتبر است.');
        }

        return $this->credentials = $data;
    }

    private function tokenCacheKey(): string
    {
        return 'google_sheets_token:'.md5((string) $this->serviceAccountEmail());
    }

    private function http(): Client
    {
        if ($this->http === null) {
            $config = [
                'timeout' => (int) config('services.google_sheets.timeout', 60),
                'connect_timeout' => 15,
            ];
            $proxy = config('services.google_sheets.proxy');
            if (is_string($proxy) && $proxy !== '') {
                $config['proxy'] = $proxy;
            }
            $this->http = new Client($config);
        }

        return $this->http;
    }
}
