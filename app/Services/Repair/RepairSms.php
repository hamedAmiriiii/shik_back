<?php

namespace App\Services\Repair;

use App\Models\RepairSetting;
use App\Models\RepairSmsLog;
use App\Models\ShopSmsLog;
use App\Services\ShopSmsQuotaService;
use App\Tools\SmsTools;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * پنل پیامک سامانهٔ تعمیرات: موجودی جدا، کسر قبل از ارسال و ثبت لاگ (مثل پیامک فروشگاه‌ها).
 */
class RepairSms
{
    public const BALANCE_KEY = 'sms_balance';

    public static function enabled(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = Schema::hasTable('repair_sms_logs');
            } catch (Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    public function balance(): int
    {
        return max(0, (int) RepairSetting::value(self::BALANCE_KEY));
    }

    /**
     * @param  bool  $force  کد ورود حتی با موجودی ناکافی ارسال می‌شود تا کسی از پنل بیرون نماند
     * @return bool ارسال شد یا نه
     */
    public function send(string $phone, string $text, string $type, bool $force = false): bool
    {
        if (! self::enabled()) {
            SmsTools::sendSms($phone, $text);

            return true;
        }

        $parts = ShopSmsQuotaService::countSmsParts($text);
        $charged = $this->deduct($parts, $force);
        if ($charged === null) {
            $this->log($phone, $text, $type, 0, [
                'delivery_status' => RepairSmsLog::STATUS_NO_CREDIT,
                'status_checked_at' => now(),
            ]);

            return false;
        }

        try {
            $response = Http::timeout(20)->get('http://api.shinapayamak.ir/v1/'.SmsTools::API_TOKEN.'/sms/send.json', [
                'gateway' => SmsTools::GATEWAY,
                'to' => $phone,
                'text' => ShopSmsQuotaService::billableText($text),
            ]);
            $json = $response->json();
            $fields = SmsTools::parseProviderResponse(is_array($json) ? $json : [], $response->successful());
        } catch (Throwable $e) {
            Log::warning('repair sms failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            $fields = [
                'delivery_status' => ShopSmsLog::STATUS_SEND_FAILED,
                'status_checked_at' => now(),
            ];
        }

        $this->log($phone, $text, $type, $charged, $fields);

        return ($fields['delivery_status'] ?? null) !== ShopSmsLog::STATUS_SEND_FAILED;
    }

    /** افزودن اعتبار پس از خرید بسته؛ موجودی جدید را برمی‌گرداند. */
    public function charge(int $count): int
    {
        if ($count < 1) {
            return $this->balance();
        }

        return DB::transaction(function () use ($count) {
            $row = $this->lockedBalanceRow();
            $balance = max(0, (int) $row->value) + $count;
            $row->value = (string) $balance;
            $row->save();

            return $balance;
        });
    }

    public function refreshStatus(RepairSmsLog $log): RepairSmsLog
    {
        if (! $log->canRefreshStatus()) {
            return $log;
        }

        $json = SmsTools::fetchSmsDeliveryStatus(
            (string) $log->batch_id !== '' ? (string) $log->batch_id : null,
            (string) $log->reference_id !== '' ? (string) $log->reference_id : null
        );
        if ($json === null) {
            return $log;
        }

        $fields = SmsTools::parseProviderResponse($json, true);
        // پاسخ استعلام بدون entry وضعیت قبلی را پاک نکند
        if (($fields['delivery_status'] ?? null) === ShopSmsLog::STATUS_UNKNOWN && $log->delivery_status) {
            unset($fields['delivery_status']);
        }
        $log->fill($fields)->save();

        return $log->refresh();
    }

    /**
     * @return int|null تعداد واحد کسرشده؛ null یعنی اعتبار کافی نبود
     */
    private function deduct(int $parts, bool $force): ?int
    {
        if ($parts < 1) {
            return 0;
        }

        return DB::transaction(function () use ($parts, $force) {
            $row = $this->lockedBalanceRow();
            $balance = max(0, (int) $row->value);
            if ($balance < $parts && ! $force) {
                return null;
            }
            $charged = min($balance, $parts);
            $row->value = (string) ($balance - $charged);
            $row->save();

            return $charged;
        });
    }

    private function lockedBalanceRow(): RepairSetting
    {
        RepairSetting::query()->firstOrCreate(['key' => self::BALANCE_KEY], ['value' => '0']);

        return RepairSetting::query()->where('key', self::BALANCE_KEY)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function log(string $phone, string $text, string $type, int $parts, array $fields): void
    {
        try {
            RepairSmsLog::create(array_merge([
                'phone' => $phone,
                'message' => $text,
                'sms_type' => $type,
                'sms_parts' => $parts,
            ], $fields));
        } catch (Throwable $e) {
            Log::warning('repair sms log failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }
}
