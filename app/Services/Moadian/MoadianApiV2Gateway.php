<?php

namespace App\Services\Moadian;

use App\Models\MoadianApiLog;
use App\Models\MoadianShopSetting;
use App\Support\OutboundHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * اتصال مستقیم (self_tsp) به API نسخه ۲ سامانه مؤدیان.
 */
class MoadianApiV2Gateway implements MoadianGateway
{
    private const LOG_LIMIT = 20000;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    private string $privateKeyPem;

    private string $certificateBase64;

    private string $memoryId;

    public function __construct(private MoadianShopSetting $settings)
    {
        $this->memoryId = strtoupper(trim((string) $settings->memory_id));
        if ($this->memoryId === '') {
            throw new MoadianConfigException('شناسه یکتای حافظه مالیاتی وارد نشده است.');
        }
        try {
            $this->privateKeyPem = MoadianCrypto::normalizePrivateKeyPem((string) $settings->private_key);
        } catch (\Throwable $e) {
            throw new MoadianConfigException('کلید خصوصی ثبت نشده یا قابل خواندن نیست.');
        }
        $this->certificateBase64 = MoadianCrypto::certificateBase64((string) $settings->certificate);
        if ($this->certificateBase64 === '') {
            throw new MoadianConfigException('گواهی امضا ثبت نشده است.');
        }
    }

    public function baseUrl(): string
    {
        $key = $this->settings->environment === MoadianShopSetting::ENV_PRODUCTION ? 'production_url' : 'sandbox_url';

        return rtrim((string) config('services.moadian.'.$key), '/');
    }

    public function fiscalInformation(): array
    {
        return $this->request('fiscal_information', 'GET', '/fiscal-information', ['memoryId' => $this->memoryId]);
    }

    public function sendInvoices(array $invoices, array $documentIds = []): array
    {
        if ($invoices === []) {
            return [];
        }
        [$keyId, $publicKey] = $this->serverPublicKey();

        $packets = [];
        foreach ($invoices as $invoice) {
            $jws = MoadianCrypto::jws($invoice['payload'], $this->privateKeyPem, $this->certificateBase64);
            $packets[] = [
                'payload' => MoadianCrypto::jwe($jws, $publicKey, $keyId),
                'header' => [
                    'requestTraceId' => $invoice['requestTraceId'],
                    'fiscalId' => $this->memoryId,
                ],
            ];
        }

        $response = $this->request('send_invoice', 'POST', '/invoice', [], $packets, $documentIds);

        $out = [];
        foreach (self::rows($response) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $uid = (string) ($row['uid'] ?? '');
            if ($uid === '') {
                continue;
            }
            $ref = $row['referenceNumber'] ?? null;
            $error = null;
            if (! $ref) {
                $error = trim((string) ($row['errorCode'] ?? '').' '.(string) ($row['errorDetail'] ?? $row['message'] ?? ''));
                if ($error === '') {
                    $error = 'سامانه شماره پیگیری برنگرداند.';
                }
            }
            $out[$uid] = ['reference_number' => $ref ? (string) $ref : null, 'error' => $error];
        }

        return $out;
    }

    public function inquiryByReferenceNumbers(array $referenceNumbers, array $documentIds = []): array
    {
        if ($referenceNumbers === []) {
            return [];
        }
        $response = $this->request('inquiry', 'GET', '/inquiry-by-reference-id', ['referenceIds' => array_values($referenceNumbers)], null, $documentIds);

        $out = [];
        foreach (self::rows($response) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ref = (string) ($row['referenceNumber'] ?? '');
            if ($ref === '') {
                continue;
            }
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $out[$ref] = [
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'errors' => self::normalizeMessages($data['error'] ?? $data['errors'] ?? []),
                'warnings' => self::normalizeMessages($data['warning'] ?? $data['warnings'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @return array{0:string, 1:string} [keyId, publicKey]
     */
    private function serverPublicKey(): array
    {
        $cacheKey = 'moadian:server-key:'.md5($this->baseUrl());
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached[0], $cached[1])) {
            return $cached;
        }

        $info = $this->request('server_information', 'GET', '/server-information');
        foreach ((array) ($info['publicKeys'] ?? []) as $key) {
            if (is_array($key) && strtoupper((string) ($key['algorithm'] ?? '')) === 'RSA' && ! empty($key['key'])) {
                $pair = [(string) ($key['id'] ?? ''), (string) $key['key']];
                Cache::put($cacheKey, $pair, now()->addHours(6));

                return $pair;
            }
        }

        throw new RuntimeException('کلید عمومی سامانه مؤدیان دریافت نشد.');
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $ttl = 120;
        $nonce = $this->request('nonce', 'GET', '/nonce', ['timeToLive' => $ttl], null, [], false);
        $value = (string) ($nonce['nonce'] ?? '');
        if ($value === '') {
            throw new RuntimeException('دریافت nonce از سامانه مؤدیان ناموفق بود.');
        }

        $this->token = MoadianCrypto::jws(['nonce' => $value, 'clientId' => $this->memoryId], $this->privateKeyPem, $this->certificateBase64);
        $this->tokenExpiresAt = time() + $ttl - 20;

        return $this->token;
    }

    private function request(string $action, string $method, string $path, array $query = [], ?array $body = null, array $documentIds = [], bool $auth = true): array
    {
        $url = $this->baseUrl().$path;
        $qs = self::queryString($query);
        if ($qs !== '') {
            $url .= '?'.$qs;
        }

        $started = microtime(true);
        $status = null;
        $raw = null;
        $error = null;

        try {
            $client = Http::withOptions(OutboundHttp::sslOptions())
                ->timeout((int) config('services.moadian.timeout', 30))
                ->withHeaders(['Accept' => 'application/json', 'Cache-Control' => 'no-cache']);
            if ($auth) {
                $client = $client->withToken($this->token());
            }

            $response = $method === 'POST'
                ? $client->withBody(MoadianCrypto::json($body ?? []), 'application/json')->post($url)
                : $client->get($url);

            $status = $response->status();
            $raw = $response->body();
            $decoded = json_decode((string) $raw, true, 512, JSON_BIGINT_AS_STRING);

            if ($status === 401 || $status === 403) {
                $error = 'احراز هویت در سامانه مؤدیان رد شد (کلید، گواهی یا شناسه حافظه را بررسی کنید). '.self::serverMessage($decoded);
                throw new MoadianConfigException(trim($error));
            }
            if ($status >= 400) {
                $error = 'خطای سامانه مؤدیان (HTTP '.$status.'). '.self::serverMessage($decoded);
                throw new RuntimeException(trim($error));
            }

            return is_array($decoded) ? $decoded : [];
        } catch (MoadianConfigException $e) {
            $error = $error ?? $e->getMessage();
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $error = 'ارتباط با سامانه مؤدیان برقرار نشد: '.$e->getMessage();
            throw new RuntimeException($error, 0, $e);
        } catch (\Throwable $e) {
            $error = $error ?? $e->getMessage();
            throw $e;
        } finally {
            $this->log($action, $method, $url, $status, $started, $documentIds, $body === null ? null : 'packets: '.count($body), $raw, $error);
        }
    }

    private function log(string $action, string $method, string $url, ?int $status, float $started, array $documentIds, ?string $request, ?string $response, ?string $error): void
    {
        try {
            MoadianApiLog::query()->create([
                'atelier_id' => $this->settings->atelier_id,
                'action' => $action,
                'method' => $method,
                'url' => mb_substr($url, 0, 500),
                'http_status' => $status,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'document_ids' => $documentIds ?: null,
                'request' => $request,
                'response' => $response === null ? null : mb_substr($response, 0, self::LOG_LIMIT),
                'error' => $error === null ? null : mb_substr($error, 0, 2000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function rows(array $response): array
    {
        foreach (['result', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }
        if ($response !== [] && array_keys($response) === range(0, count($response) - 1)) {
            return $response;
        }

        return [];
    }

    private static function queryString(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    private static function serverMessage($decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }
        foreach (['message', 'error', 'errorDetail', 'detail', 'title'] as $key) {
            if (! empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }

        return '';
    }

    /**
     * @return array<int, array{code:string, message:string}>
     */
    public static function normalizeMessages($list): array
    {
        $out = [];
        foreach ((array) $list as $item) {
            if (is_string($item)) {
                $out[] = ['code' => '', 'message' => $item];
            } elseif (is_array($item)) {
                $out[] = [
                    'code' => (string) ($item['code'] ?? $item['errorCode'] ?? ''),
                    'message' => (string) ($item['message'] ?? $item['msg'] ?? $item['detail'] ?? MoadianCrypto::json($item)),
                ];
            }
        }

        return $out;
    }
}