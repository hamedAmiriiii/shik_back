<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        
        // تنظیم timezone برای scheduled tasks به تهران
        $schedule->timezone('Asia/Tehran');
        
        // بررسی و صفر کردن اعتبارهای منقضی شده - روزانه در ساعت 10 صبح (به وقت تهران)
        $schedule->command('credits:expire')
            ->dailyAt('10:00')
            ->before(function () {
                \Log::info('Scheduled task: credits:expire - شروع اجرا', [
                    'time' => now()->format('Y-m-d H:i:s'),
                    'timezone' => config('app.timezone')
                ]);
            })
            ->after(function () {
                \Log::info('Scheduled task: credits:expire - اجرا شد');
            });
        
        // ارسال یادآوری قسط‌ها - روزانه در ساعت 10 صبح (به وقت تهران)
        $schedule->command('installments:send-reminders')
            ->dailyAt('10:00')
            ->before(function () {
                \Log::info('Scheduled task: installments:send-reminders - شروع اجرا', [
                    'time' => now()->format('Y-m-d H:i:s'),
                    'timezone' => config('app.timezone')
                ]);
            })
            ->after(function () {
                \Log::info('Scheduled task: installments:send-reminders - اجرا شد');
            });

        // تبدیل چک‌های صادرهٔ سررسیدشده به هزینه - روزانه در ساعت 00:05 (به وقت تهران)
        $schedule->command('cheques:convert-due')
            ->dailyAt('00:05')
            ->before(function () {
                \Log::info('Scheduled task: cheques:convert-due - شروع اجرا', [
                    'time' => now()->format('Y-m-d H:i:s'),
                    'timezone' => config('app.timezone'),
                ]);
            })
            ->after(function () {
                \Log::info('Scheduled task: cheques:convert-due - اجرا شد');
            });

        // به‌روزرسانی وضعیت تحویل پیامک‌های فروشگاه از شینا
        $schedule->command('shop-sms:refresh-statuses --limit=100')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // باشگاه هوشمند: RFM + سگمنت + پیشنهاد اقدام — هر شب ۲۳:۰۰ تهران
        $schedule->command('smart-customer:nightly')
            ->dailyAt('23:00')
            ->withoutOverlapping()
            ->before(function () {
                \Log::info('Scheduled task: smart-customer:nightly - شروع اجرا', [
                    'time' => now()->format('Y-m-d H:i:s'),
                    'timezone' => config('app.timezone'),
                ]);
            })
            ->after(function () {
                \Log::info('Scheduled task: smart-customer:nightly - اجرا شد');
            });
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
