<?php

namespace App\Services\GoogleSheets;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * اتصال حساب گوگلِ خود هر فروشگاه (OAuth). فقط به فایل‌هایی که خود برنامه می‌سازد دسترسی دارد (drive.file).
 */
class ShopGoogleOAuth
{
    public const KEY_REFRESH_TOKEN = 'google_oauth_refresh_token';

    public const KEY_EMAIL = 'google_oauth_email';

    /** کلیدهایی که نباید از API عمومی تنظیمات خوانده یا نوشته شوند */
    public const PRIVATE_SETTING_KEYS = [self::KEY_REFRESH_TOKEN];

    private const SCOPES = 'openid email https://www.googleapis.com/auth/drive.file';

    private const STATE_TTL_SECONDS = 900;

    /** @var Client|null */
    private $http;

    public function isEnabled(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function isConnected(int $atelierId): bool
    {
        return $this->refreshToken($atelierId) !== null;
    }

    public function connectedEmail(int $atelierId): ?string
    {
        return $this->setting($atelierId, self::KEY_EMAIL);
    }

    public function authorizationUrl(int $atelierId, string $returnUrl): string
    {
        if (! $this->isEnabled()) {
            throw new GoogleSheetsException('ورود با گوگل روی سرور تنظیم نشده است.');
        }

        $state = Crypt::encryptString((string) json_encode([
            'a' => $atelierId,
            'r' => $returnUrl,
            'e' => time() + self::STATE_TTL_SECONDS,
        ]));

        return (string) config('services.google_sheets.oauth_auth_url').'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{atelier_id: int, return_url: string}
     */
    public function decodeState(string $state): array
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException $e) {
            throw new GoogleSheetsException('درخواست ورود با گوگل نامعتبر است. دوباره تلاش کنید.');
        }
        if (! is_array($data) || empty($data['a']) || (int) ($data['e'] ?? 0) < time()) {
            throw new GoogleSheetsException('مهلت ورود با گوگل تمام شد. دوباره تلاش کنید.');
        }

        return ['atelier_id' => (int) $data['a'], 'return_url' => (string) ($data['r'] ?? '')];
    }

    /**
     * کد برگشتی گوگل را با توکن عوض می‌کند و برای فروشگاه ذخیره می‌کند.
     */
    public function completeAuthorization(int $atelierId, string $code): string
    {
        $data = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);

        $refresh = (string) ($data['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new GoogleSheetsException('گوگل اجازهٔ دسترسی دائمی نداد. از حساب گوگل دسترسی برنامه را حذف و دوباره وارد شوید.');
        }

        $email = $this->emailFromIdToken((string) ($data['id_token'] ?? '')) ?? '';
        $previousEmail = $this->connectedEmail($atelierId);

        $this->putSetting($atelierId, self::KEY_REFRESH_TOKEN, Crypt::encryptString($refresh));
        $this->putSetting($atelierId, self::KEY_EMAIL, $email);
        if ($previousEmail !== null && strcasecmp($previousEmail, $email) !== 0) {
            // شیت قبلی در درایو حساب دیگری است؛ با حساب جدید شیت تازه ساخته شود
            $this->putSetting($atelierId, ShopGoogleSheetExportService::KEY_SPREADSHEET_ID, '');
        }
        $this->putSetting($atelierId, ShopGoogleSheetExportService::KEY_LAST_EXPORT_ERROR, '');

        $token = (string) ($data['access_token'] ?? '');
        if ($token !== '') {
            Cache::put($this->tokenCacheKey($atelierId), $token, max(60, (int) ($data['expires_in'] ?? 3600) - 300));
        }

        return $email;
    }

    public function accessToken(int $atelierId): string
    {
        $cached = Cache::get($this->tokenCacheKey($atelierId));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refresh = $this->refreshToken($atelierId);
        if ($refresh === null) {
            throw new GoogleSheetsException('ابتدا با حساب گوگل فروشگاه وارد شوید.');
        }

        try {
            $data = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
            ]);
        } catch (GoogleSheetsException $e) {
            if ($e->getCode() === 400 || $e->getCode() === 401) {
                $this->disconnect($atelierId);
                throw new GoogleSheetsException('دسترسی حساب گوگل لغو شده است. دوباره با گوگل وارد شوید.', $e->getCode(), $e);
            }
            throw $e;
        }

        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new GoogleSheetsException('پاسخ توکن گوگل نامعتبر است.');
        }
        Cache::put($this->tokenCacheKey($atelierId), $token, max(60, (int) ($data['expires_in'] ?? 3600) - 300));

        return $token;
    }

    public function forgetAccessToken(int $atelierId): void
    {
        Cache::forget($this->tokenCacheKey($atelierId));
    }

    public function disconnect(int $atelierId): void
    {
        $this->forgetAccessToken($atelierId);
        $this->putSetting($atelierId, self::KEY_REFRESH_TOKEN, '');
        $this->putSetting($atelierId, self::KEY_EMAIL, '');
        $this->putSetting($atelierId, ShopGoogleSheetExportService::KEY_SPREADSHEET_ID, '');
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        try {
            $response = $this->http()->post((string) config('services.google_sheets.token_url'), [
                'form_params' => array_merge($params, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                ]),
            ]);
        } catch (ConnectException $e) {
            throw new GoogleSheetsException('اتصال سرور به گوگل برقرار نشد. احتمالاً دسترسی سرور به Google API مسدود است.', 0, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw new GoogleSheetsException('دریافت توکن گوگل ناموفق بود'.($status ? ' ('.$status.')' : '').'.', $status, $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * id_token مستقیم از گوگل (روی TLS) گرفته شده؛ فقط ایمیل از آن خوانده می‌شود.
     */
    private function emailFromIdToken(string $idToken): ?string
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $email = is_array($payload) ? ($payload['email'] ?? null) : null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function refreshToken(int $atelierId): ?string
    {
        $stored = $this->setting($atelierId, self::KEY_REFRESH_TOKEN);
        if ($stored === null) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            return null;
        }
    }

    private function clientId(): string
    {
        return trim((string) config('services.google_sheets.oauth_client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.google_sheets.oauth_client_secret'));
    }

    private function redirectUri(): string
    {
        $configured = trim((string) config('services.google_sheets.oauth_redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        // پشت پراکسی/CDN درخواست http دیده می‌شود ولی آدرس ثبت‌شده در گوگل https است
        $url = url('/api/google-sheet/oauth/callback');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! in_array($host, ['localhost', '127.0.0.1'], true)) {
            $url = preg_replace('#^http://#i', 'https://', $url);
        }

        return $url;
    }

    private function tokenCacheKey(int $atelierId): string
    {
        return 'google_sheets_oauth_token:'.$atelierId;
    }

    private function setting(int $atelierId, string $key): ?string
    {
        $value = Setting::query()
            ->where('atelier_id', $atelierId)
            ->where('key', $key)
            ->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function putSetting(int $atelierId, string $key, string $value): void
    {
        $previous = Setting::contextAtelierId();
        Setting::setContextAtelierId($atelierId);
        try {
            Setting::set($key, $value);
        } finally {
            Setting::setContextAtelierId($previous);
        }
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
