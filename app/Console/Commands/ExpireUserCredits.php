<?php

namespace App\Console\Commands;

use App\Models\UserShiksho;
use App\Models\Setting;
use App\Tools\SmsTools;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ExpireUserCredits extends Command
{
    private const DEFAULT_EXPIRY_DAYS = 60;

    private const WARNING_BEFORE_DAYS = 7;

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

    /** @var array<string, int> */
    private array $expiryDaysCache = [];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("شروع بررسی اعتبارهای منقضی شده...");

        $campaignExpired = \App\Services\UserCreditGrantService::expireLapsedForAtelier();
        $this->info("اعتبار کمپین منقضی‌شده برای {$campaignExpired} مشتری برداشته شد.");

        $atelierIds = UserShiksho::query()
            ->where('credit', '>', 0)
            ->whereNotNull('credit_last_updated_at')
            ->distinct()
            ->pluck('atelier_id');

        $previousContext = Setting::contextAtelierId();
        try {
            foreach ($atelierIds as $atelierId) {
                $atelierId = $atelierId !== null ? (int) $atelierId : null;
                $expiryDays = $this->expiryDaysFor($atelierId);
                $label = $atelierId !== null ? "#{$atelierId}" : 'بدون فروشگاه';
                $this->info("فروشگاه {$label}: مهلت اعتبار {$expiryDays} روز");

                // 1. هشدار ۷ روز قبل از انقضا (اگر مهلت کوتاه‌تر از ۷ روز باشد هشدار نمی‌رود)
                if ($expiryDays > self::WARNING_BEFORE_DAYS) {
                    $this->sendWarningSms($atelierId, $expiryDays);
                }

                // 2. صفر کردن اعتبارهای منقضی شده
                $this->expireCredits($atelierId, $expiryDays);
            }
        } finally {
            Setting::setContextAtelierId($previousContext);
        }

        $this->info("عملیات با موفقیت انجام شد.");
        return 0;
    }

    /**
     * مهلت انقضای اعتبار از تنظیمات همان فروشگاه (پیش‌فرض ۶۰ روز).
     */
    private function expiryDaysFor(?int $atelierId): int
    {
        $key = $atelierId === null ? 'global' : (string) $atelierId;
        if (! isset($this->expiryDaysCache[$key])) {
            Setting::setContextAtelierId($atelierId);
            $days = (int) Setting::get('credit_expiry_days', self::DEFAULT_EXPIRY_DAYS);
            $this->expiryDaysCache[$key] = $days >= 1 && $days <= 365 ? $days : self::DEFAULT_EXPIRY_DAYS;
        }

        return $this->expiryDaysCache[$key];
    }

    private function usersOf(?int $atelierId)
    {
        $q = UserShiksho::query()
            ->where('credit', '>', 0)
            ->whereNotNull('credit_last_updated_at');

        return $atelierId !== null
            ? $q->where('atelier_id', $atelierId)
            : $q->whereNull('atelier_id');
    }

    /**
     * ارسال پیامک هشدار به کاربرانی که ۷ روز دیگر اعتبارشان منقضی می‌شود
     */
    private function sendWarningSms(?int $atelierId, int $expiryDays): void
    {
        $warningDate = Carbon::now()->subDays($expiryDays - self::WARNING_BEFORE_DAYS);
        $expiryDate = Carbon::now()->subDays($expiryDays);

        $usersToWarn = $this->usersOf($atelierId)
            ->where('credit_last_updated_at', '<=', $warningDate)
            ->where('credit_last_updated_at', '>', $expiryDate) // هنوز منقضی نشده
            ->whereNull('last_warning_sent_at')
            ->get();

        $this->info("تعداد کاربرانی که باید هشدار دریافت کنند: " . $usersToWarn->count());

        foreach ($usersToWarn as $user) {
            try {
                $creditAmount = number_format($user->credit, 0);
                $daysLeft = max(1, $expiryDays - (int) Carbon::parse($user->credit_last_updated_at)->diffInDays(Carbon::now()));

                $shopName = SmsTools::shopSmsBrand($atelierId);
                $message = "{$shopName}\nاعتبار شما به مبلغ {$creditAmount} تومان در حال اتمام است ({$daysLeft} روز دیگر)";

                try {
                    SmsTools::sendShopSms($user->phone, $message, null, $user->credit, 'warning', $atelierId);
                } catch (\App\Exceptions\InsufficientShopSmsQuotaException $e) {
                    $this->warn("اعتبار پیامک فروشگاه #{$atelierId} کافی نیست برای {$user->phone}");
                    continue;
                }

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
     */
    private function expireCredits(?int $atelierId, int $expiryDays): void
    {
        $expiryDate = Carbon::now()->subDays($expiryDays);

        $usersToExpire = $this->usersOf($atelierId)
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
