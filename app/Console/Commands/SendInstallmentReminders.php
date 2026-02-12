<?php

namespace App\Console\Commands;

use App\Models\Installment;
use App\Tools\SmsTools;
use Illuminate\Console\Command;
use Morilog\Jalali\Jalalian;

class SendInstallmentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'installments:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ارسال پیامک یادآوری برای قسط‌هایی که 3 روز دیگر سر می‌رسند';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $threeDaysLater = now()->addDays(3)->toDateString();
        $today = now()->toDateString();

        // پیدا کردن قسط‌های پرداخت نشده که در 3 روز آینده سر می‌رسند
        $installments = Installment::where('is_paid', false)
            ->whereBetween('due_date', [$today, $threeDaysLater])
            ->with('purchase')
            ->get();

        $sentCount = 0;
        $failedCount = 0;

        foreach ($installments as $installment) {
            if (!$installment->purchase || !$installment->purchase->phone) {
                continue;
            }

            try {
                $dueDateJalali = Jalalian::fromCarbon(\Carbon\Carbon::parse($installment->due_date))
                    ->format('Y/m/d');
                
                $amountFormatted = number_format($installment->amount, 0);
                
                $message = "شیکشو\n";
                $message .= "یادآوری قسط\n";
                $message .= "مبلغ: {$amountFormatted} تومان\n";
                $message .= "تاریخ سررسید: {$dueDateJalali}\n";
                $message .= "لطفاً قسط خود را پرداخت کنید";

                SmsTools::sendShopSms(
                    $installment->purchase->phone,
                    $message,
                    (string) $installment->purchase->id,
                    null,
                    'installment_reminder'
                );

                $sentCount++;
                $this->info("پیامک برای قسط #{$installment->installment_number} خرید #{$installment->purchase->id} ارسال شد");

            } catch (\Exception $e) {
                $failedCount++;
                $this->error("خطا در ارسال پیامک برای قسط #{$installment->installment_number}: " . $e->getMessage());
            }
        }

        $this->info("تعداد پیامک‌های ارسال شده: {$sentCount}");
        if ($failedCount > 0) {
            $this->warn("تعداد خطاها: {$failedCount}");
        }

        return Command::SUCCESS;
    }
}

