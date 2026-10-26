<?php

namespace App\Tools;

use App\Models\Atelier;
use App\Models\LogSms;
use App\Models\ShopSmsLog;
use App\Models\UserShiksho;
use App\Services\ShopSmsQuotaService;
use App\Services\Sms\MelipayamakClient;
use App\Services\Sms\ShinaPayamakClient;
use App\Services\Sms\SmsGatewayConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kavenegar;

class SmsTools
{
    /** @deprecated از config('sms.shina.api_token') استفاده کنید */
    public const API_TOKEN = "sa345478720:L2ZSDrZ7lEYzxark92MWhH2mveIUxQ7KABqI";

    /** @deprecated از config('sms.shina.gateway') استفاده کنید */
    public const GATEWAY = '10003000207';

    /**
     * نام نمایشی فروشگاه برای ابتدای پیامک (نام رکورد ateliers).
     */
    public static function shopSmsBrand(?int $atelierId): string
    {
        if ($atelierId === null || $atelierId === 0) {
            return 'فروشگاه';
        }

        static $cache = [];
        if (! array_key_exists($atelierId, $cache)) {
            $name = trim((string) Atelier::where('id', $atelierId)->value('name'));
            $cache[$atelierId] = $name !== '' ? $name : 'فروشگاه';
        }

        return $cache[$atelierId];
    }

    public static function getBalance()
    {
        if (SmsGatewayConfig::isMelipayamak()) {
            return MelipayamakClient::fromConfig()->getCredit();
        }

        return (new ShinaPayamakClient())->getBalance();
    }

    /**
     * ارسال پیامک متن آزاد (سیستمی / OTP متنی).
     * اگر پروایدر ملی‌پیامک باشد و کد OTP قابل تشخیص باشد، از پترن استفاده می‌شود.
     *
     * @param  bool  $writeSystemLog  ثبت در log_sms (صفحه کدهای دوعاملی)
     */
    public static function sendSms(string $receivers, string $text, bool $writeSystemLog = true)
    {
        $otpCode = self::extractOtpCode($text);
        if ($otpCode !== null && SmsGatewayConfig::isMelipayamak()) {
            $shopName = self::extractShopNameFromOtpText($text);
            $result = self::sendOtpSms($receivers, $otpCode, $shopName);
        } else {
            $result = self::dispatchFreeText($receivers, $text);
        }

        if ($writeSystemLog) {
            LogSms::create([
                'text' => $text,
                'number' => SmsGatewayConfig::isMelipayamak()
                    ? (SmsGatewayConfig::melipayamakFrom() ?: 'melipayamak')
                    : (new ShinaPayamakClient())->logSender(),
                'receivers' => $receivers,
                'creator_id' => Auth::id(),
            ]);
        }

        return $result;
    }

    /**
     * ارسال OTP با پترن ملی‌پیامک (یا متن آزاد روی شینا).
     */
    public static function sendOtpSms(string $phone, string $code, ?string $shopName = null): array
    {
        if (! SmsGatewayConfig::isMelipayamak()) {
            $brand = $shopName !== null && $shopName !== '' ? $shopName : 'وبینو';
            $text = "{$brand}\nکد احراز هویت شما : {$code}";

            return self::dispatchFreeText($phone, $text);
        }

        $brand = ($shopName !== null && $shopName !== '') ? $shopName : 'وبینو';
        $bodyId = SmsGatewayConfig::melipayamakOtpBodyId();

        // خط خدماتی اشتراکی (الگو) به لیست سیاه هم می‌رسد. الگوی ۵۶۴۷۰۳ دو متغیر دارد.
        if ($bodyId !== null) {
            $mode = SmsGatewayConfig::melipayamakOtpVarsMode();
            $vars = ($mode === 'code' && $bodyId !== 564703) ? [$code] : [$brand, $code];

            $client = MelipayamakClient::fromConfig();
            $json = $client->sendByBaseNumber($phone, $vars, $bodyId);
            $interpreted = MelipayamakClient::interpretSendResponse($json);

            return array_merge($json, [
                'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                'ok' => $interpreted['ok'],
                'reference_id' => $interpreted['reference_id'] ?? null,
            ]);
        }

        $text = "{$brand}\nکد احراز هویت شما : {$code}";

        return self::dispatchFreeText($phone, $text);
    }

    /**
     * هشدار کمبود اعتبار پیامک فروشگاه به صاحب فروشگاه (سیستمی؛ از سهمیه فروشگاه کم نمی‌شود).
     * الگوی پیشنهادی ملی‌پیامک:
     * {0}
     * اعتبار ارسال پیامک فروشگاه شما رو به اتمام است. موجودی باقیمانده: {1} پیامک
     * webinoo-plus
     *
     * @return array{provider?: string, ok?: bool, raw?: array, reference_id?: string|null}
     */
    public static function sendLowSmsQuotaAlert(string $phone, string $shopName, int $balance): array
    {
        $brand = trim($shopName) !== '' ? trim($shopName) : 'فروشگاه';
        $balanceText = (string) max(0, $balance);
        $message = "{$brand}\nاعتبار ارسال پیامک فروشگاه شما رو به اتمام است. موجودی باقیمانده: {$balanceText} پیامک";

        if (SmsGatewayConfig::isMelipayamak()) {
            $bodyId = SmsGatewayConfig::melipayamakLowQuotaBodyId();
            if ($bodyId !== null) {
                $client = MelipayamakClient::fromConfig();
                $json = $client->sendByBaseNumber($phone, [$brand, $balanceText], $bodyId);
                $interpreted = MelipayamakClient::interpretSendResponse($json);

                LogSms::create([
                    'text' => $message,
                    'number' => SmsGatewayConfig::melipayamakFrom() ?: 'melipayamak',
                    'receivers' => $phone,
                    'creator_id' => Auth::id(),
                ]);

                return array_merge($json, [
                    'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                    'ok' => $interpreted['ok'],
                    'reference_id' => $interpreted['reference_id'] ?? null,
                ]);
            }
        }

        // مستقیم متن آزاد؛ sendSms ممکن است عدد موجودی را به‌عنوان OTP تشخیص دهد.
        $result = self::dispatchFreeText($phone, $message);
        LogSms::create([
            'text' => $message,
            'number' => SmsGatewayConfig::isMelipayamak()
                ? (SmsGatewayConfig::melipayamakFrom() ?: 'melipayamak')
                : (new ShinaPayamakClient())->logSender(),
            'receivers' => $phone,
            'creator_id' => Auth::id(),
        ]);

        return $result;
    }

    /**
     * پیامک افزایش اعتبار باشگاه.
     * الگوی ۵۶۴۷۷۵: {0} {1}عزیز مبلغ {2} تومان به اعتبار شما برای خرید بعدی اضافه شد webinoo-plus.ir
     */
    public static function sendLoyaltyCreditSms(
        string $phone,
        float|int|string $amount,
        ?int $atelierId = null,
        ?string $purchaseId = null,
        ?string $customerName = null
    ) {
        $shopName = self::shopSmsBrand($atelierId);
        $name = self::loyaltyCustomerName($phone, $atelierId, $customerName);
        $amountText = number_format(max(0, (int) round((float) $amount)), 0, '.', ',');
        $message = "{$shopName}\n{$name}عزیز مبلغ {$amountText} تومان به اعتبار شما برای خرید بعدی اضافه شد";

        $bodyId = SmsGatewayConfig::isMelipayamak()
            ? SmsGatewayConfig::melipayamakCreditBodyId()
            : null;

        if ($bodyId !== null) {
            return self::dispatchShopPattern(
                $phone,
                $message,
                [$shopName, "\n".$name, $amountText],
                $bodyId,
                $purchaseId,
                (float) $amount,
                'credit',
                $atelierId
            );
        }

        return self::sendShopSms($phone, $message, $purchaseId, (float) $amount, 'credit', $atelierId);
    }

    /**
     * پیامک خوش‌آمد ثبت در باشگاه مشتریان.
     * الگوی ۵۶۸۹۵۳: به باشگاه مشتریان {0} خوش آمدید
     */
    public static function sendCustomerWelcomeSms(
        string $phone,
        ?int $atelierId = null
    ) {
        $shopName = self::shopSmsBrand($atelierId);
        $message = "به باشگاه مشتریان {$shopName} خوش آمدید";

        $bodyId = SmsGatewayConfig::isMelipayamak()
            ? SmsGatewayConfig::melipayamakWelcomeBodyId()
            : null;

        if ($bodyId !== null) {
            return self::dispatchShopPattern(
                $phone,
                $message,
                [$shopName],
                $bodyId,
                null,
                null,
                'customer_register',
                $atelierId
            );
        }

        return self::sendShopSms($phone, $message, null, null, 'customer_register', $atelierId);
    }

    /**
     * پیامک تشکر از خرید.
     * الگوی ۵۶۶۹۰۳: {0} با تشکر از خرید شما
     */
    public static function sendPurchaseThanksSms(
        string $phone,
        ?int $atelierId = null,
        ?string $purchaseId = null
    ) {
        $shopName = self::shopSmsBrand($atelierId);
        $message = "{$shopName}\nبا تشکر از خرید شما";

        $bodyId = SmsGatewayConfig::isMelipayamak()
            ? SmsGatewayConfig::melipayamakPurchaseBodyId()
            : null;

        if ($bodyId !== null) {
            return self::dispatchShopPattern(
                $phone,
                $message,
                [$shopName],
                $bodyId,
                $purchaseId,
                null,
                'purchase',
                $atelierId
            );
        }

        return self::sendShopSms($phone, $message, $purchaseId, null, 'purchase', $atelierId);
    }

    /**
     * اطلاع موجود شدن کالا در دسته.
     * الگوی ۵۶۶۹۰۱: {0} کالای «{1}» در دسته «{2}» دوباره موجود شد.
     */
    public static function sendCategoryRestockSms(
        string $phone,
        string $productName,
        string $categoryName,
        ?int $atelierId = null
    ) {
        $shopName = self::shopSmsBrand($atelierId);
        $productName = trim($productName) !== '' ? trim($productName) : 'کالا';
        $categoryName = trim($categoryName) !== '' ? trim($categoryName) : 'دسته';
        $message = "{$shopName}\nکالای «{$productName}» در دسته «{$categoryName}» دوباره موجود شد.";

        $bodyId = SmsGatewayConfig::isMelipayamak()
            ? SmsGatewayConfig::melipayamakCategoryRestockBodyId()
            : null;

        if ($bodyId !== null) {
            return self::dispatchShopPattern(
                $phone,
                $message,
                [$shopName, $productName, $categoryName],
                $bodyId,
                null,
                null,
                'category_stock_notify',
                $atelierId
            );
        }

        return self::sendShopSms($phone, $message, null, null, 'category_stock_notify', $atelierId);
    }

    /**
     * لینک مشاهده کالا (پیام جدا).
     * الگوی ۵۶۶۹۰۲: {0} مشاهده کالا: {1}
     */
    public static function sendCategoryProductLinkSms(
        string $phone,
        string $viewUrl,
        ?int $atelierId = null
    ) {
        $shopName = self::shopSmsBrand($atelierId);
        $viewUrl = trim($viewUrl);
        if ($viewUrl === '') {
            return ['ok' => false, 'error' => 'empty_url'];
        }
        $message = "{$shopName}\nمشاهده کالا:\n{$viewUrl}";

        $bodyId = SmsGatewayConfig::isMelipayamak()
            ? SmsGatewayConfig::melipayamakCategoryLinkBodyId()
            : null;

        if ($bodyId !== null) {
            return self::dispatchShopPattern(
                $phone,
                $message,
                [$shopName, $viewUrl],
                $bodyId,
                null,
                null,
                'category_stock_notify_link',
                $atelierId
            );
        }

        return self::sendShopSms($phone, $viewUrl, null, null, 'category_stock_notify_link', $atelierId);
    }

    /**
     * ارسال پیامک برای فروشگاه و ثبت در جدول shop_sms_logs
     *
     * @param  string  $phone  شماره تلفن گیرنده
     * @param  string  $message  متن پیام
     * @param  string|null  $purchaseId  ID خرید (اختیاری)
     * @param  float|null  $creditAmount  مبلغ اعتبار (اختیاری)
     * @param  string  $smsType  نوع پیامک (پیش‌فرض: purchase)
     * @param  int|null  $atelierId  شناسه فروشگاه
     * @return array
     */
    public static function sendShopSms(
        string $phone,
        string $message,
        ?string $purchaseId = null,
        ?float $creditAmount = null,
        string $smsType = 'purchase',
        ?int $atelierId = null
    ) {
        if ($atelierId !== null && $atelierId > 0) {
            ShopSmsQuotaService::deductForMessage($atelierId, $message);
        }

        $payload = [
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'message' => $message,
            'purchase_id' => $purchaseId,
            'credit_amount' => $creditAmount,
            'sms_type' => $smsType,
        ];

        try {
            $result = self::dispatchFreeText($phone, $message, true);
            $providerFields = self::providerFieldsFromDispatch($result);
            $payload = array_merge($payload, $providerFields);

            self::createShopSmsLog($payload);

            return $result['raw'] ?? $result;
        } catch (\Throwable $e) {
            Log::warning('shop sms send failed', [
                'phone' => $phone,
                'atelier_id' => $atelierId,
                'error' => $e->getMessage(),
            ]);

            $payload['delivery_status'] = ShopSmsLog::STATUS_SEND_FAILED;
            $payload['status_checked_at'] = now();
            self::createShopSmsLog($payload);

            return [
                'error' => $e->getMessage(),
                'status' => [
                    'code' => 0,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<int, string|int|float>  $vars
     */
    protected static function dispatchShopPattern(
        string $phone,
        string $message,
        array $vars,
        int $bodyId,
        ?string $purchaseId,
        ?float $creditAmount,
        string $smsType,
        ?int $atelierId
    ) {
        if ($atelierId !== null && $atelierId > 0) {
            ShopSmsQuotaService::deductForMessage($atelierId, $message);
        }

        $payload = [
            'atelier_id' => $atelierId,
            'phone' => $phone,
            'message' => $message,
            'purchase_id' => $purchaseId,
            'credit_amount' => $creditAmount,
            'sms_type' => $smsType,
        ];

        try {
            $json = MelipayamakClient::fromConfig()->sendByBaseNumber($phone, $vars, $bodyId);
            $interpreted = MelipayamakClient::interpretSendResponse($json);
            $result = [
                'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                'ok' => $interpreted['ok'],
                'raw' => $json,
                'reference_id' => $interpreted['reference_id'] ?? null,
                'delivery_status' => $interpreted['delivery_status'],
            ];
            $payload = array_merge($payload, self::providerFieldsFromDispatch($result));
            self::createShopSmsLog($payload);

            return $result['raw'] ?? $result;
        } catch (\Throwable $e) {
            Log::warning('shop pattern sms send failed', [
                'phone' => $phone,
                'atelier_id' => $atelierId,
                'body_id' => $bodyId,
                'error' => $e->getMessage(),
            ]);

            $payload['delivery_status'] = ShopSmsLog::STATUS_SEND_FAILED;
            $payload['status_checked_at'] = now();
            self::createShopSmsLog($payload);

            return [
                'error' => $e->getMessage(),
                'status' => [
                    'code' => 0,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    protected static function loyaltyCustomerName(string $phone, ?int $atelierId, ?string $customerName): string
    {
        $name = trim((string) $customerName);
        if ($name === '' && $atelierId !== null && $atelierId > 0) {
            $name = trim((string) UserShiksho::query()
                ->where('phone', $phone)
                ->where('atelier_id', $atelierId)
                ->value('name'));
        }

        $name = trim((string) preg_replace('/\s*عزیز\s*$/u', '', $name));

        return $name !== '' ? $name : 'همراه';
    }

    /**
     * استعلام وضعیت پیامک از پروایدر فعال
     *
     * @param  string|null  $batchId
     * @param  string|null  $referenceId
     * @return array|null
     */
    public static function fetchSmsDeliveryStatus(?string $batchId = null, ?string $referenceId = null): ?array
    {
        if (SmsGatewayConfig::isMelipayamak()) {
            if ($referenceId === null || $referenceId === '') {
                return null;
            }

            return MelipayamakClient::fromConfig()->getDeliveries($referenceId);
        }

        return (new ShinaPayamakClient())->status($batchId, $referenceId);
    }

    /**
     * به‌روزرسانی وضعیت یک لاگ از روی پاسخ پروایدر
     */
    public static function refreshShopSmsLogStatus(ShopSmsLog $log): ShopSmsLog
    {
        if (! self::shopSmsDeliveryColumnsReady()) {
            return $log;
        }

        $attrs = $log->getAttributes();
        $referenceId = isset($attrs['reference_id']) ? (string) $attrs['reference_id'] : '';
        $batchId = isset($attrs['batch_id']) ? (string) $attrs['batch_id'] : '';

        if ($referenceId === '' && $batchId === '') {
            return $log;
        }

        $json = self::fetchSmsDeliveryStatus(
            $batchId !== '' ? $batchId : null,
            $referenceId !== '' ? $referenceId : null
        );

        if ($json === null) {
            return $log;
        }

        if (SmsGatewayConfig::isMelipayamak()) {
            $fields = MelipayamakClient::interpretDeliveryResponse($json, $referenceId !== '' ? $referenceId : null);
        } else {
            $fields = self::extractProviderFieldsFromResponse($json, true);
        }

        if (! empty($fields)) {
            $log->fill($fields);
            $log->save();
            $log->refresh();
        }

        return $log;
    }

    /**
     * @param  array  $json
     * @param  bool  $httpOk
     * @return array
     */
    public static function extractProviderFieldsFromResponse(array $json, bool $httpOk = true): array
    {
        if (! self::shopSmsDeliveryColumnsReady()) {
            return [];
        }

        if (isset($json['RetStatus'])) {
            $interpreted = MelipayamakClient::interpretSendResponse($json);
            $fields = [
                'status_checked_at' => now(),
                'delivery_status' => $interpreted['delivery_status'],
            ];
            if (! empty($interpreted['reference_id'])) {
                $fields['reference_id'] = $interpreted['reference_id'];
            }

            return $fields;
        }

        return self::parseProviderResponse($json, $httpOk);
    }

    /**
     * فیلدهای وضعیت (batch_id, reference_id, delivery_status, ...) از پاسخ ارسال یا استعلام شینا
     *
     * @return array<string, mixed>
     */
    public static function parseProviderResponse(array $json, bool $httpOk = true): array
    {
        $fields = [
            'status_checked_at' => now(),
        ];

        $apiCode = data_get($json, 'status.code');
        $entries = data_get($json, 'entries');
        $entry = null;
        if (is_array($entries) && count($entries) > 0 && is_array($entries[0])) {
            $entry = $entries[0];
        }

        if (array_key_exists('batch_id', $json) && $json['batch_id'] !== null && $json['batch_id'] !== '') {
            $fields['batch_id'] = (string) $json['batch_id'];
        }

        if (is_array($entry)) {
            if (isset($entry['reference_id']) && $entry['reference_id'] !== '' && $entry['reference_id'] !== null) {
                $fields['reference_id'] = (string) $entry['reference_id'];
            }
            if (! empty($entry['status'])) {
                $fields['delivery_status'] = strtoupper((string) $entry['status']);
            }
            if (! empty($entry['datetime'])) {
                $fields['provider_datetime'] = (string) $entry['datetime'];
            }
        }

        $ok = $httpOk && ($apiCode === null || (int) $apiCode === 200);
        if (! $ok && empty($fields['delivery_status'])) {
            $fields['delivery_status'] = ShopSmsLog::STATUS_SEND_FAILED;
        } elseif (empty($fields['delivery_status'])) {
            $fields['delivery_status'] = ShopSmsLog::STATUS_UNKNOWN;
        }

        return $fields;
    }

    /**
     * @return array{provider: string, ok: bool, raw: array, http_ok?: bool}
     */
    protected static function dispatchFreeText(string $phone, string $text, bool $withOptOutSuffix = false): array
    {
        if (SmsGatewayConfig::isMelipayamak()) {
            $from = SmsGatewayConfig::melipayamakFrom();
            if ($from === '') {
                Log::warning('melipayamak free-text requires dedicated from number');

                return [
                    'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                    'ok' => false,
                    'raw' => [
                        'Value' => '-3',
                        'RetStatus' => 0,
                        'StrRetStatus' => 'FromNumberMissing',
                        'error' => 'برای پیامک متن آزاد در ملی‌پیامک، شماره خط اختصاصی (from) را در تنظیمات وارد کنید. خط اشتراکی فقط پترن OTP را پشتیبانی می‌کند.',
                    ],
                ];
            }

            $sendText = self::ensureMelipayamakOptOut(
                $withOptOutSuffix ? ShopSmsQuotaService::billableText($text) : $text
            );
            $json = MelipayamakClient::fromConfig()->send($phone, $from, $sendText);
            $interpreted = MelipayamakClient::interpretSendResponse($json);

            return [
                'provider' => SmsGatewayConfig::PROVIDER_MELIPAYAMAK,
                'ok' => $interpreted['ok'],
                'raw' => $json,
                'reference_id' => $interpreted['reference_id'] ?? null,
                'delivery_status' => $interpreted['delivery_status'],
            ];
        }

        $sendText = $withOptOutSuffix
            ? ShopSmsQuotaService::billableText($text)
            : ($text." \n لغو11");
        $shina = new ShinaPayamakClient();
        $sent = $shina->send($phone, $sendText);

        return [
            'provider' => SmsGatewayConfig::PROVIDER_SHINA,
            'ok' => $sent['http_ok'],
            'http_ok' => $sent['http_ok'],
            'raw' => $sent['json'],
        ];
    }

    /**
     * @param  array{provider?: string, ok?: bool, raw?: array, http_ok?: bool, reference_id?: string, delivery_status?: string}  $result
     * @return array<string, mixed>
     */
    protected static function providerFieldsFromDispatch(array $result): array
    {
        if (! self::shopSmsDeliveryColumnsReady()) {
            return [];
        }

        if (($result['provider'] ?? '') === SmsGatewayConfig::PROVIDER_MELIPAYAMAK) {
            $fields = [
                'status_checked_at' => now(),
                'delivery_status' => $result['delivery_status']
                    ?? (($result['ok'] ?? false) ? ShopSmsLog::STATUS_SENT : ShopSmsLog::STATUS_SEND_FAILED),
            ];
            if (! empty($result['reference_id'])) {
                $fields['reference_id'] = (string) $result['reference_id'];
            } elseif (! empty($result['raw']) && is_array($result['raw'])) {
                $interpreted = MelipayamakClient::interpretSendResponse($result['raw']);
                if (! empty($interpreted['reference_id'])) {
                    $fields['reference_id'] = $interpreted['reference_id'];
                }
                $fields['delivery_status'] = $interpreted['delivery_status'];
            }

            return $fields;
        }

        $json = is_array($result['raw'] ?? null) ? $result['raw'] : [];

        return self::parseProviderResponse($json, (bool) ($result['http_ok'] ?? $result['ok'] ?? false));
    }

    /**
     * خط اختصاصی ملی‌پیامک بدون «لغو11» در انتهای متن، ارسال را رد می‌کند (کد ۱۵).
     */
    protected static function ensureMelipayamakOptOut(string $text): string
    {
        $trimmed = rtrim($text);
        if ($trimmed === '' || str_ends_with($trimmed, 'لغو11')) {
            return $trimmed;
        }

        return $trimmed.ShopSmsQuotaService::OPT_OUT_SUFFIX;
    }

    protected static function extractOtpCode(string $text): ?string
    {
        if (preg_match('/(?<!\\d)(\\d{4,8})(?!\\d)/u', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    protected static function extractShopNameFromOtpText(string $text): ?string
    {
        $lines = preg_split("/\\R/u", trim($text)) ?: [];
        if (count($lines) >= 2 && ! preg_match('/^\\d+$/', trim($lines[0]))) {
            $first = trim($lines[0]);

            return $first !== '' ? $first : null;
        }

        return null;
    }

    protected static function createShopSmsLog(array $payload): void
    {
        if (! self::shopSmsDeliveryColumnsReady()) {
            unset(
                $payload['batch_id'],
                $payload['reference_id'],
                $payload['delivery_status'],
                $payload['provider_datetime'],
                $payload['status_checked_at']
            );
        }

        ShopSmsLog::create($payload);
    }

    protected static function shopSmsDeliveryColumnsReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasColumn('shop_sms_logs', 'delivery_status');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    public static function sendSmsK(string $receivers, string $text)
    {
        try {
            $result = Kavenegar::Send('500031430320', $receivers, $text);
            if ($result) {
                foreach ($result as $r) {
                    echo "messageid = $r->messageid";
                    echo "message = $r->message";
                    echo "status = $r->status";
                    echo "statustext = $r->statustext";
                    echo "sender = $r->sender";
                    echo "receptor = $r->receptor";
                    echo "date = $r->date";
                    echo "cost = $r->cost";
                }
            }

            return $result;
        } catch (\Kavenegar\Exceptions\ApiException $e) {
            echo $e->errorMessage();
        } catch (\Kavenegar\Exceptions\HttpException $e) {
            echo $e->errorMessage();
        }
    }
}
