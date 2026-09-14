<?php

namespace App\Tools;

use App\Models\Atelier;
use App\Models\LogSms;
use App\Models\ShopSmsLog;
use App\Services\ShopSmsQuotaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Kavenegar;

class SmsTools
{
    public const API_TOKEN = "sa345478720:L2ZSDrZ7lEYzxark92MWhH2mveIUxQ7KABqI";

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
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/account/balance.json');

        return $response->json();
    }

    public static function sendSms(string $receivers, string $text)
    {
        $response = Http::get('http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/send.json', [
            'gateway' => self::GATEWAY,
            'to' => $receivers,
            'text' => $text . " \n لغو11",
        ]);

        LogSms::create([
            'text' => $text,
            'number' => '1000528554',
            'receivers' => $receivers,
            'creator_id' => Auth::id(),
        ]);

        return $response->json();
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
            $response = Http::timeout(20)->get(
                'http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/send.json',
                [
                    'gateway' => self::GATEWAY,
                    'to' => $phone,
                    'text' => $message . " \n لغو11",
                ]
            );

            $json = $response->json();
            if (! is_array($json)) {
                $json = [];
            }

            $providerFields = self::extractProviderFieldsFromResponse($json, $response->successful());
            $payload = array_merge($payload, $providerFields);

            self::createShopSmsLog($payload);

            return $json;
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
     * استعلام وضعیت پیامک از شینا
     *
     * @param  string|null  $batchId
     * @param  string|null  $referenceId
     * @return array|null
     */
    public static function fetchSmsDeliveryStatus(?string $batchId = null, ?string $referenceId = null): ?array
    {
        if (($referenceId === null || $referenceId === '') && ($batchId === null || $batchId === '')) {
            return null;
        }

        $query = [];
        if ($referenceId !== null && $referenceId !== '') {
            $query['reference_id'] = $referenceId;
        } else {
            $query['batch_id'] = $batchId;
        }

        $response = Http::timeout(20)->get(
            'http://api.shinapayamak.ir/v1/' . self::API_TOKEN . '/sms/status.json',
            $query
        );

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * به‌روزرسانی وضعیت یک لاگ از روی پاسخ شینا
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

        $fields = self::extractProviderFieldsFromResponse($json, true);
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
