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
