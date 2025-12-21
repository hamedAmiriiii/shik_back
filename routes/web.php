<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Route موقت برای پاک کردن cache - بعد از استفاده حذف کنید!
Route::get('/clear-cache', function () {
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    $timezone = config('app.timezone');
    $now = \Carbon\Carbon::now();
    return "Cache cleared successfully!<br>Timezone: {$timezone}<br>Current time: {$now->format('Y-m-d H:i:s')}";
});

/*Route::get('/test', function () {
    $target = '/home2/kermanph/atelier/storage/app/public';
    $shortcut = '/home2/kermanph/public_html/api/storage';
    symlink($target, $shortcut);
    //\Illuminate\Support\Facades\Artisan::call('storage:link');
});*/
