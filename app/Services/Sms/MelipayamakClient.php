<?php

namespace App\Services\Sms;

use App\Models\ShopSmsLog;
use App\Support\OutboundHttp;
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
     * ارسال از خط خدماتی اشتراکی (SendByBaseNumber2 / BaseServiceNumber).
     * هر درخواست فقط یک گیرنده می‌پذیرد. متغیرها با ; جدا می‌شوند.
     *
     * @param  string|array<int, string|int|float>  $textOrVars
     */
    public function sendByBaseNumber(string $to, $textOrVars, int $bodyId): array
    {
        $text = is_array($textOrVars)
            ? self::joinPatternVariables($textOrVars)
            : (string) $textOrVars;

        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return self::localError(16);
        }
        if (trim($text) === '') {
            return self::localError(17);
        }

        $last = null;
        $recIds = [];
        foreach ($recipients as $recipient) {
            $last = $this->post('BaseServiceNumber', [
                'to' => $recipient,
                'text' => $text,
                'bodyId' => $bodyId,
            ]);
            $interpreted = self::interpretSendResponse($last);
            if (! $interpreted['ok']) {
                return $last;
            }
            if (! empty($interpreted['reference_id'])) {
                $recIds[] = (string) $interpreted['reference_id'];
            }
        }

        if ($last === null) {
            return self::localError(11);
        }
        if (count($recipients) > 1) {
            $last['Value'] = implode(',', $recIds);
        }

        return $last;
    }

    /**
     * ارسال متن آزاد با خط اختصاصی.
     * هر فراخوانی حداکثر ۱۰۰ گیرنده؛ بیشتر از آن به چند درخواست شکسته می‌شود.
     */
    public function send(string $to, string $from, string $text, bool $isFlash = false): array
    {
        $recipients = $this->normalizeRecipients($to);
        if ($recipients === []) {
            return self::localError(16);
        }
        if (trim($text) === '') {
            return self::localError(17);
        }
        if (trim($from) === '') {
            return self::localError(5);
        }

        $chunks = array_chunk($recipients, 100);
        $recIds = [];
        $last = null;

        foreach ($chunks as $chunk) {
            $last = $this->post('SendSMS', [
                'to' => implode(',', $chunk),
                'from' => $from,
                'text' => $text,
                'isFlash' => $isFlash,
            ]);
            $interpreted = self::interpretSendResponse($last);
            if (! $interpreted['ok']) {
                return $last;
            }
            if (! empty($interpreted['reference_id'])) {
                $recIds[] = (string) $interpreted['reference_id'];
            }
        }

        if ($last === null) {
            return self::localError(11);
        }

        if (count($chunks) > 1) {
            $last['Value'] = implode(',', $recIds);
        }

        return $last;
    }

    public function getDeliveries(string $recId): array
    {
        $first = trim(explode(',', $recId)[0] ?? '');

        return $this->post('GetDeliveries2', [
            'recID' => $first,
        ]);
    }

    /**
     * @return array{ok: bool, reference_id?: string|null, delivery_status: string, raw: array, error_code?: string, error_message?: string}
     */
    public static function interpretSendResponse(array $json): array
    {
        $ret = (int) ($json['RetStatus'] ?? 0);
        $value = trim((string) ($json['Value'] ?? ''));

        if ($ret === 1 && self::looksLikeRecIds($value)) {
            return [
                'ok' => true,
                'reference_id' => $value,
                'delivery_status' => ShopSmsLog::STATUS_SENT,
                'raw' => $json,
            ];
        }

        $code = self::resolveErrorCode($ret, $value);

        return [
            'ok' => false,
            'reference_id' => null,
            'delivery_status' => self::mapSendErrorStatus($code),
            'raw' => $json,
            'error_code' => (string) $code,
            'error_message' => self::describeSendError($code, $str),
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
        $code = null;
        if (is_array($value) && isset($value[0])) {
            $code = (int) $value[0];
        } elseif (is_numeric($value)) {
            $code = (int) $value;
        }

        $fields['delivery_status'] = self::mapDeliveryCode($code);

        return $fields;
    }

    /**
     * کدهای وضعیت GetDeliveries2.
     */
    protected static function mapDeliveryCode(?int $code): string
    {
        return match ($code) {
            0, 8, 200 => ShopSmsLog::STATUS_SENT,
            1 => ShopSmsLog::STATUS_DELIVERED,
            2 => ShopSmsLog::STATUS_UNDELIVERED,
            3, 16 => ShopSmsLog::STATUS_FAILED,
            35 => ShopSmsLog::STATUS_BLACKLIST,
            300 => ShopSmsLog::STATUS_FILTERED,
            400 => ShopSmsLog::STATUS_ENQUEUED,
            500 => ShopSmsLog::STATUS_REJECTED,
            5, 100, -1, null => ShopSmsLog::STATUS_UNKNOWN,
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

            return self::localError(0, 'نام کاربری یا کلید API ملی‌پیامک خالی است.');
        }

        $payload = array_merge($data, [
            'username' => $this->username,
            'password' => $this->apiKey,
        ]);

        try {
            $response = Http::withOptions(OutboundHttp::sslOptions())
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl.'/'.$method, $payload);

            $decoded = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
            $json = is_array($decoded) ? $decoded : null;
            if (! is_array($json)) {
                Log::warning('melipayamak invalid response', [
                    'method' => $method,
                    'http_status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return [
                    'Value' => '',
                    'RetStatus' => 0,
                    'StrRetStatus' => 'پاسخ ملی‌پیامک قابل خواندن نبود.',
                    'http_status' => $response->status(),
                ];
            }

            return self::annotate($json, $method);
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

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    protected static function annotate(array $json, string $method): array
    {
        $ret = (int) ($json['RetStatus'] ?? 0);
        if ($ret === 1) {
            return $json;
        }

        $value = trim((string) ($json['Value'] ?? ''));
        $code = self::resolveErrorCode($ret, $value);
        $message = self::describeSendError($code, (string) ($json['StrRetStatus'] ?? ''));
        $json['StrRetStatus'] = $message;

        Log::warning('melipayamak api rejected', [
            'method' => $method,
            'ret_status' => $ret,
            'value' => $value,
            'message' => $message,
        ]);

        return $json;
    }

    protected static function resolveErrorCode(int $ret, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) === 1) {
            $asInt = (int) $value;
            if ($asInt !== 1 && array_key_exists($asInt, self::sendErrorMessages())) {
                return $asInt;
            }
        }

        return $ret;
    }

    protected static function describeSendError(int $code, string $strRetStatus): string
    {
        $known = self::sendErrorMessages()[$code] ?? null;
        $str = trim($strRetStatus);
        if ($known !== null) {
            return $known;
        }
        if ($str !== '' && strcasecmp($str, 'Ok') !== 0 && ! preg_match('/^-?\d+$/', $str)) {
            return $str;
        }

        return 'ارسال پیامک از ملی‌پیامک ناموفق بود.';
    }

    protected static function mapSendErrorStatus(int $code): string
    {
        return match ($code) {
            7, 14, 15, -10 => ShopSmsLog::STATUS_INAPPROPRIATE_CONTENT,
            16, 18 => ShopSmsLog::STATUS_INVALID_NUMBER,
            35 => ShopSmsLog::STATUS_BLACKLIST,
            default => ShopSmsLog::STATUS_SEND_FAILED,
        };
    }

    /**
     * @return array<int, string>
     */
    protected static function sendErrorMessages(): array
    {
        return [
            -112 => 'دسترسی وب‌سرویس برای این حساب غیرفعال است.',
            -111 => 'IP درخواست‌کننده برای ملی‌پیامک نامعتبر است.',
            -110 => 'باید به‌جای رمز عبور، ApiKey وب‌سرویس را وارد کنید.',
            -109 => 'IP سرور در پنل ملی‌پیامک به‌عنوان IP مجاز ثبت نشده است.',
            -108 => 'IP به‌خاطر تلاش ناموفق API مسدود شده است.',
            -10 => 'در متغیرهای الگو لینک وجود دارد.',
            -7 => 'خطا در شماره فرستندهٔ خط خدماتی. با پشتیبانی ملی‌پیامک تماس بگیرید.',
            -6 => 'خطای داخلی ملی‌پیامک. با پشتیبانی تماس بگیرید.',
            -5 => 'متغیرهای ارسالی با الگوی تأییدشده هم‌خوان نیست. الگو دو مقدار می‌خواهد: عنوان و کد.',
            -4 => 'شناسه الگو صحیح نیست یا هنوز تأیید نشده است.',
            -3 => 'خط خدماتی در سامانه تعریف نشده است.',
            -2 => 'وب‌سرویس الگو در هر بار فقط یک شماره می‌پذیرد.',
            -1 => 'دسترسی وب‌سرویس خدماتی غیرفعال است.',
            0 => 'نام کاربری یا ApiKey ملی‌پیامک اشتباه است.',
            2 => 'اعتبار حساب ملی‌پیامک کافی نیست.',
            3 => 'محدودیت ارسال روزانه ملی‌پیامک.',
            4 => 'محدودیت حجم ارسال ملی‌پیامک.',
            5 => 'شماره خط فرستنده معتبر نیست. خط اختصاصی را در تنظیمات درگاه وارد کنید.',
            6 => 'سامانه ملی‌پیامک در حال به‌روزرسانی است.',
            7 => 'متن پیامک حاوی کلمه فیلترشده است.',
            9 => 'ارسال از خط عمومی با وب‌سرویس ممکن نیست. شماره خط اختصاصی لازم است.',
            10 => 'کاربر ملی‌پیامک فعال نیست.',
            11 => 'پیامک ارسال نشد.',
            12 => 'مدارک کاربر در ملی‌پیامک کامل نیست.',
            14 => 'متن پیامک حاوی لینک است و رد شد.',
            15 => 'انتهای متن پیامک باید لغو11 باشد.',
            16 => 'شماره گیرنده‌ای یافت نشد.',
            17 => 'متن پیامک خالی است.',
            18 => 'شماره موبایل معتبر نیست.',
            19 => 'سقف ارسال روزانهٔ API پر شده است.',
            35 => 'شماره گیرنده در لیست سیاه مخابرات است.',
        ];
    }

    /**
     * @return array{Value: string, RetStatus: int, StrRetStatus: string}
     */
    protected static function localError(int $code, ?string $message = null): array
    {
        return [
            'Value' => (string) $code,
            'RetStatus' => $code,
            'StrRetStatus' => $message ?? self::describeSendError($code, ''),
        ];
    }

    protected static function looksLikeRecIds(string $value): bool
    {
        if ($value === '' || $value === '11') {
            return false;
        }

        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^\d{16,}$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * متغیرهای الگو به ترتیب {0};{1};... — جداکنندهٔ REST همان ; است.
     *
     * @param  array<int, string|int|float>  $vars
     */
    public static function joinPatternVariables(array $vars): string
    {
        $clean = [];
        foreach ($vars as $var) {
            $value = trim(str_replace([';', '؛', ','], ' ', (string) $var));
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $clean[] = $value;
        }

        return implode(';', $clean);
    }

    /**
     * @return list<string>
     */
    protected function normalizeRecipients(string $to): array
    {
        $parts = preg_split('/[,،;]+/u', $to) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $mobile = $this->normalizeMobile(trim($part));
            if ($mobile === '') {
                continue;
            }
            $out[] = $mobile;
        }

        return $out;
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

        return $digits;
    }
}
