<?php

namespace App\Console\Commands;

use App\Models\MoadianApiLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** فقط لاگ درخواست‌های API پاک می‌شود؛ صورتحساب‌ها (moadian_documents) نگهداری دائمی دارند. */
class MoadianPruneLogs extends Command
{
    protected $signature = 'moadian:prune-logs {--days= : نگهداری لاگ به روز}';

    protected $description = 'پاک‌سازی لاگ‌های قدیمی درخواست‌های سامانه مؤدیان';

    public function handle()
    {
        if (! Schema::hasTable('moadian_api_logs')) {
            return 0;
        }

        $days = (int) ($this->option('days') ?: config('services.moadian.log_retention_days', 90));
        $days = max(7, $days);

        $deleted = MoadianApiLog::query()->where('created_at', '<', now()->subDays($days))->delete();
        $this->info("deleted={$deleted}");

        return 0;
    }
}
