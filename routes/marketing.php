<?php

use App\Http\Controllers\Admin\MarketerAdminController;
use App\Http\Controllers\Marketing\MarketerAuthController;
use App\Http\Controllers\Marketing\MarketerPanelController;
use App\Http\Controllers\Marketing\MarketingTrackController;
use App\Http\Middleware\AuthenticateMarketer;
use Illuminate\Support\Facades\Route;

/*
| سیستم بازاریاب‌ها — پنل: webinoo-plus.ir/newuser
| ورود فقط با شماره موبایل + کد پیامکی، توکن در هدر X-Marketer-Token
*/

Route::prefix('marketing')->name('marketing.')->group(function () {
    Route::post('auth/send-code', [MarketerAuthController::class, 'sendCode'])
        ->middleware('throttle:5,1');
    Route::post('auth/verify', [MarketerAuthController::class, 'verify'])
        ->middleware('throttle:15,1');

    Route::post('visit', [MarketingTrackController::class, 'visit'])
        ->middleware('throttle:60,1');
    Route::post('claim', [MarketingTrackController::class, 'claim'])
        ->middleware('throttle:20,1');

    Route::prefix('panel')->middleware(AuthenticateMarketer::class)->group(function () {
        Route::get('dashboard', [MarketerPanelController::class, 'dashboard']);
        Route::put('profile', [MarketerAuthController::class, 'updateProfile']);
        Route::post('logout', [MarketerAuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('admin/marketers')->name('admin.marketers.')->group(function () {
    Route::get('/', [MarketerAdminController::class, 'index']);
    Route::post('/', [MarketerAdminController::class, 'store']);
    Route::get('settings', [MarketerAdminController::class, 'settings']);
    Route::put('settings', [MarketerAdminController::class, 'updateSettings']);
    Route::get('{marketer}', [MarketerAdminController::class, 'show'])->where('marketer', '[0-9]+');
    Route::put('{marketer}', [MarketerAdminController::class, 'update'])->where('marketer', '[0-9]+');
    Route::post('{marketer}/payouts', [MarketerAdminController::class, 'storePayout'])->where('marketer', '[0-9]+');
    Route::delete('{marketer}/payouts/{payout}', [MarketerAdminController::class, 'destroyPayout'])
        ->where(['marketer' => '[0-9]+', 'payout' => '[0-9]+']);
});
