<?php

namespace App\Console\Commands;

use App\Models\ShopSmsLog;
use App\Tools\SmsTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshShopSmsDeliveryStatuses extends Command
{
    protected $signature = 'shop-sms:refresh-statuses {--limit=100 : حداکثر تعداد رکورد در هر اجرا}';

    protected $description = 'به‌روزرسانی وضعیت تحویل پیامک‌های فروشگاه از سامانه شینا';

    public function handle()
    {
        if (! Schema::hasColumn('shop_sms_logs', 'delivery_status')) {
            $this->warn('ستون delivery_status وجود ندارد. ابتدا SQL/migration را اجرا کنید.');

            return 1;
        }

        $limit = max(1, (int) $this->option('limit'));

        $logs = ShopSmsLog::query()
            ->where(function ($q) {
                $q->whereNull('delivery_status')
                    ->orWhereNotIn('delivery_status', ShopSmsLog::FINAL_STATUSES);
            })
            ->where(function ($q) {
                $q->whereNotNull('reference_id')
                    ->orWhereNotNull('batch_id');
            })
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($logs as $log) {
            try {
                SmsTools::refreshShopSmsLogStatus($log);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("log #{$log->id}: ".$e->getMessage());
            }
        }

        $this->info("checked={$logs->count()} updated={$updated} failed={$failed}");

        return 0;
    }
}
