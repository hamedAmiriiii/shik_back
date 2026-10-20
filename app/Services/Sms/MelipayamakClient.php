<?php

namespace App\Services\Sms;

use App\Models\ShopSmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MelipayamakClient
{
    protected string $baseUrl;

    public function __construct(
        protected string $username,
        protected string $apiKey,
        ?string $baseUrl = null
    ) {
        $this->baseUrl = rtrim($baseUrl ?: (string) config('sms.melipayamak.base_url'), '/');
    }

    public static function fromConfig(): self
    {
        return new self(
            SmsGatewayConfig::melipayamakUsername(),
            SmsGatewayConfig::melipayamakApiKey()
        );
    }

    public function getCredit(): array
    {
        return $this->post('GetCredit', []);
    }

    /**
     * ارسال با پترن خط خدماتی اشتراکی.
     *
     * @param  string|array<int, string|int|float>  $textOrVars  متغیرها با ; یا آرایه
     */
    public function sendByBaseNumber(string $to, $textOrVars, int $bodyId): array
    {
        $text = is_array($textOrVars)
            ? implode(';', array_map(static fn ($v) => (string) $v, $textOrVars))
            : (string) $textOrVars;

        return $this->post('BaseServiceNumber', [
            'to' => $this->normalizeMobile($to),
            'text' => $text,
            'bodyId' => $bodyId,
        ]);
    }

    /**
     * ارسال متن آزاد با خط اختصاصی.
     */
    public function send(string $to, string $from, string $text, bool $isFlash = false): array
    {
        return $this->post('SendSMS', [
            'to' => $this->normalizeMobile($to),
            'from' => $from,
            'text' => $text,
            'isFlash' => $isFlash,
        ]);
    }

    public function getDeliveries(string $recId): array
    {
        return $this->post('GetDeliveries2', [
            'recId' => $recId,
        ]);
    }

    /**
     * @return array{ok: bool, reference_id?: string, delivery_status: string, raw: array}
     */
    public static function interpretSendResponse(array $json): array
    {
        $ret = (int) ($json['RetStatus'] ?? 0);
        $value = isset($json['Value']) ? (string) $json['Value'] : '';

        if ($ret === 1 && $value !== '' && ctype_digit($value) && strlen($value) >= 15) {
            return [
                'ok' => true,
                'reference_id' => $value,
                'delivery_status' => ShopSmsLog::STATUS_SENT,
                'raw' => $json,
            ];
        }

        // گاهی Value کد خطای منفی است
        return [
            'ok' => false,
            'reference_id' => $value !== '' && ctype_digit($value) ? $value : null,
            'delivery_status' => ShopSmsLog::STATUS_SEND_FAILED,
            'raw' => $json,
            'error_code' => $value !== '' ? $value : (string) $ret,
            'error_message' => (string) ($json['StrRetStatus'] ?? 'SendFailed'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function interpretDeliveryResponse(array $json, ?string $recId = null): array
    {
        $fields = [
            'status_checked_at' => now(),
        ];
        if ($recId) {
            $fields['reference_id'] = $recId;
        }

        $ret = (int) ($json['RetStatus'] ?? 0);
        if ($ret !== 1) {
            $fields['delivery_status'] = ShopSmsLog::STATUS_UNKNOWN;

            return $fields;
        }

        $value = $json['Value'] ?? null;
        // GetDeliveries2 ممکن است عدد وضعیت یا آرایه برگرداند
        $code = null;
        if (is_array($value) && isset($value[0])) {
            $code = (int) $value[0];
        } elseif (is_numeric($value)) {
            $code = (int) $value;
        }

        $fields['delivery_status'] = self::mapDeliveryCode($code);

        return $fields;
    }

    protected static function mapDeliveryCode(?int $code): string
    {
        return match ($code) {
            1 => ShopSmsLog::STATUS_DELIVERED,       // رسیده به گوشی
            2 => ShopSmsLog::STATUS_FAILED,          // نرسیده به گوشی
            4 => ShopSmsLog::STATUS_SENT,            // رسیده به مخابرات
            8 => ShopSmsLog::STATUS_PENDING,         // رسیده به مخابرات / در صف
            16 => ShopSmsLog::STATUS_ENQUEUED,
            null => ShopSmsLog::STATUS_UNKNOWN,
            default => ShopSmsLog::STATUS_UNKNOWN,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function post(string $method, array $data): array
    {
        if ($this->username === '' || $this->apiKey === '') {
            Log::warning('melipayamak credentials missing');

            return [
                'Value' => '0',
                'RetStatus' => 0,
                'StrRetStatus' => 'CredentialsMissing',
            ];
        }

        $payload = array_merge($data, [
            'username' => $this->username,
            'password' => $this->apiKey,
        ]);

        try {
            $response = Http::timeout(20)
                ->asForm()
                ->post($this->baseUrl.'/'.$method, $payload);

            $json = $response->json();
            if (! is_array($json)) {
                return [
                    'Value' => '',
                    'RetStatus' => 0,
                    'StrRetStatus' => 'InvalidResponse',
                    'http_status' => $response->status(),
                ];
            }

            return $json;
        } catch (\Throwable $e) {
            Log::warning('melipayamak request failed', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return [
                'Value' => '',
                'RetStatus' => 0,
                'StrRetStatus' => $e->getMessage(),
            ];
        }
    }

    protected function normalizeMobile(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        return $digits !== '' ? $digits : $phone;
    }
}
