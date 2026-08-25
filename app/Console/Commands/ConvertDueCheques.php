<?php

namespace App\Console\Commands;

use App\Models\Cheque;
use Carbon\Carbon;
use Illuminate\Console\Command;
use RuntimeException;

class ConvertDueCheques extends Command
{
    protected $signature = 'cheques:convert-due';

    protected $description = 'وصول خودکار چک‌های سررسیدشده (صادره→هزینه، دریافتی→درآمد)';

    public function handle()
    {
        $today = Carbon::today('Asia/Tehran')->toDateString();
        $this->info("وصول چک‌های با سررسید تا {$today}...");

        $cheques = Cheque::query()
            ->where('status', Cheque::STATUS_PENDING)
            ->whereDate('due_date', '<=', $today)
            ->orderBy('id')
            ->get();

        $converted = 0;
        foreach ($cheques as $cheque) {
            try {
                $cheque->clear($today);
                $converted++;
            } catch (RuntimeException $e) {
                $this->warn("چک #{$cheque->id}: ".$e->getMessage());
            }
        }

        $this->info("تعداد چک‌های وصول‌شده: {$converted}");

        return 0;
    }
}
