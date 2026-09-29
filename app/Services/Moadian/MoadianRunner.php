<?php

namespace App\Services\Moadian;

use App\Models\MoadianShopSetting;
use Illuminate\Support\Facades\Cache;

/**
 * یک دور کامل برای یک فروشگاه: جمع‌آوری ← ارسال ← استعلام.
 * خطای پیکربندی فروشگاه را متوقف (paused) می‌کند؛ هیچ خطایی به بیرون (فروش) نشت نمی‌کند.
 */
class MoadianRunner
{
    public static function gateway(MoadianShopSetting $settings): MoadianGateway
    {
        return $settings->connection_mode === MoadianShopSetting::MODE_SELF_TSP
            ? new MoadianApiV2Gateway($settings)
            : new MoadianTspGateway();
    }

    /**
     * @return array{ok:bool, message:?string, collect:array, send:array, inquiry:array}
     */
    public static function runShop(MoadianShopSetting $settings): array
    {
        $report = ['ok' => true, 'message' => null, 'collect' => [], 'send' => [], 'inquiry' => []];

        $lockKey = 'moadian:run:'.$settings->atelier_id;
        if (! Cache::add($lockKey, 1, 600)) {
            $report['message'] = 'پردازش دیگری برای این فروشگاه در حال اجراست.';

            return $report;
        }

        try {
            if ($settings->enabled) {
                $report['collect'] = (new MoadianDocumentService($settings))->collect();
            }

            if ($settings->canSend()) {
                $gateway = self::gateway($settings);
                $report['send'] = (new MoadianSender($settings, $gateway))->send();
                $report['inquiry'] = (new MoadianInquiryService($settings, $gateway))->inquire();
            }
        } catch (MoadianConfigException $e) {
            $report['ok'] = false;
            $report['message'] = $e->getMessage();
            $settings->forceFill(['paused_reason' => $e->getMessage()])->save();
        } catch (\Throwable $e) {
            $report['ok'] = false;
            $report['message'] = $e->getMessage();
            report($e);
        } finally {
            try {
                $settings->forceFill(['last_run_at' => now()])->save();
            } catch (\Throwable $e) {
                report($e);
            }
            Cache::forget($lockKey);
        }

        return $report;
    }
}
