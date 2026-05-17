<?php

namespace App\Console\Commands;

use App\Models\UserShiksho;
use App\Models\Setting;
use App\Tools\SmsTools;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ExpireUserCredits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'credits:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'بررسی و صفر کردن اعتبارهای منقضی شده و ارسال هشدار به کاربران';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // دریافت تعداد روز انقضا از Setting (پیش‌فرض 60 روز)
        $expiryDays = (int) Setting::get('credit_expiry_days', 60);
        $warningDays = $expiryDays - 7; // 7 روز قبل از انقضا

        $this->info("شروع بررسی اعتبارهای منقضی شده...");
        $this->info("تعداد روز انقضا: {$expiryDays} روز");
        $this->info("تعداد روز هشدار: {$warningDays} روز");

        // 1. ارسال هشدار به کاربرانی که 7 روز دیگر اعتبارشان منقضی می‌شود
        $this->sendWarningSms($warningDays);

        // 2. صفر کردن اعتبارهای منقضی شده
        $this->expireCredits($expiryDays);

        $this->info("عملیات با موفقیت انجام شد.");
        return 0;
    }

    /**
     * ارسال پیامک هشدار به کاربرانی که 7 روز دیگر اعتبارشان منقضی می‌شود
     *
     * @param int $warningDays
     * @return void
     */
    private function sendWarningSms($warningDays)
    {
        // تاریخ دقیق 53 روز پیش (7 روز قبل از انقضا)
        $warningDate = Carbon::now()->subDays($warningDays);
        
        // پیدا کردن کاربرانی که:
        // - اعتبار > 0 دارند
        // - credit_last_updated_at آنها دقیقاً warningDays روز پیش است (یا قبل از آن)
        // - هنوز هشدار ارسال نشده (last_warning_sent_at null است)
        // - اما هنوز 60 روز کامل نگذشته (یعنی هنوز منقضی نشده)
        $expiryDate = Carbon::now()->subDays($warningDays + 7); // 60 روز پیش
        
        $usersToWarn = UserShiksho::where('credit', '>', 0)
            ->whereNotNull('credit_last_updated_at')
            ->where('credit_last_updated_at', '<=', $warningDate)
            ->where('credit_last_updated_at', '>', $expiryDate) // هنوز منقضی نشده
            ->whereNull('last_warning_sent_at')
            ->get();

        $this->info("تعداد کاربرانی که باید هشدار دریافت کنند: " . $usersToWarn->count());

        foreach ($usersToWarn as $user) {
            try {
                // فرمت کردن مبلغ اعتبار
                $creditAmount = number_format($user->credit, 0);
                
                $shopName = SmsTools::shopSmsBrand($user->atelier_id ? (int) $user->atelier_id : null);
                $message = "{$shopName}\nاعتبار شما به مبلغ {$creditAmount} تومان در حال اتمام است (7 روز دیگر)";
                
                // ارسال پیامک برای فروشگاه (ثبت در shop_sms_logs)
                SmsTools::sendShopSms($user->phone, $message, null, $user->credit, 'warning');
                
                // به‌روزرسانی last_warning_sent_at
                $user->last_warning_sent_at = Carbon::now();
                $user->save();
                
                $this->line("پیامک هشدار به {$user->phone} ارسال شد. مبلغ اعتبار: {$creditAmount} تومان");
            } catch (\Exception $e) {
                $this->error("خطا در ارسال پیامک به {$user->phone}: " . $e->getMessage());
            }
        }
    }

    /**
     * صفر کردن اعتبارهای منقضی شده
     *
     * @param int $expiryDays
     * @return void
     */
    private function expireCredits($expiryDays)
    {
        $expiryDate = Carbon::now()->subDays($expiryDays);
        
        // پیدا کردن کاربرانی که:
        // - اعتبار > 0 دارند
        // - credit_last_updated_at آنها از expiryDate گذشته است
        $usersToExpire = UserShiksho::where('credit', '>', 0)
            ->whereNotNull('credit_last_updated_at')
            ->whereDate('credit_last_updated_at', '<=', $expiryDate->format('Y-m-d'))
            ->get();

        $this->info("تعداد کاربرانی که اعتبارشان منقضی شده: " . $usersToExpire->count());

        $expiredCount = 0;
        foreach ($usersToExpire as $user) {
            $oldCredit = $user->credit;
            
            // صفر کردن اعتبار (بدون تغییر updated_at)
            $user->timestamps = false;
            $user->credit = 0;
            $user->save();
            $user->timestamps = true;
            
            $expiredCount++;
            $this->line("اعتبار کاربر {$user->phone} صفر شد. مبلغ قبلی: " . number_format($oldCredit, 0) . " تومان");
        }

        $this->info("تعداد {$expiredCount} اعتبار منقضی شد و صفر شد.");
    }
}

