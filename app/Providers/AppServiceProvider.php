<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // تنظیم timezone به صورت صریح
        date_default_timezone_set(config('app.timezone'));
        Carbon::setLocale(config('app.locale'));

        if ($this->app->runningInConsole()) {
            return;
        }

        $started = microtime(true);
        $this->app->terminating(function () use ($started) {
            $ms = (int) round((microtime(true) - $started) * 1000);
            if ($ms < 800) {
                return;
            }

            $request = request();
            \Illuminate\Support\Facades\Log::warning('slow.request', [
                'ms' => $ms,
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        });
    }
}
