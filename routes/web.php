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

Route::get('/oil', function () {
    return view('oil');
});

// Route موقت برای پاک کردن cache - بعد از استفاده حذف کنید!
Route::get('/clear-cache', function () {
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    $timezone = config('app.timezone');
    $now = \Carbon\Carbon::now();
    return "Cache cleared successfully!<br>Timezone: {$timezone}<br>Current time: {$now->format('Y-m-d H:i:s')}";
});

Route::get('/test', function () {
    
     $target = '/home/webinop1/hesabres/storage/app/public';

    $shortcut = '/home/webinop1/public_html/api.webinoplus.ir/storage';

    // بررسی source
    if (!is_dir($target)) {
        return response()->json([
            'success' => false,
            'error' => 'Target directory does not exist',
            'target' => $target,
        ], 500);
    }

    // اگر shortcut قبلی وجود دارد
    if (is_link($shortcut)) {

        $oldTarget = readlink($shortcut);

        if ($oldTarget === $target) {
            return response()->json([
                'success' => true,
                'message' => 'Symlink already exists and is correct',
                'shortcut' => $shortcut,
                'target' => $target,
                'link_target' => $oldTarget,
            ]);
        }

        // حذف symlink اشتباه
        unlink($shortcut);

    } elseif (file_exists($shortcut)) {

        return response()->json([
            'success' => false,
            'error' => 'Shortcut exists but is not a symlink',
            'shortcut' => $shortcut,
        ], 500);
    }

    // بررسی parent
    $parent = dirname($shortcut);

    if (!is_dir($parent)) {
        return response()->json([
            'success' => false,
            'error' => 'Document Root directory does not exist',
            'parent' => $parent,
        ], 500);
    }

    // ساخت symlink
    if (!symlink($target, $shortcut)) {
        return response()->json([
            'success' => false,
            'error' => 'symlink() failed',
            'target' => $target,
            'shortcut' => $shortcut,
        ], 500);
    }

    return response()->json([
        'success' => true,
        'message' => 'Storage symlink created successfully',

        'target' => $target,
        'shortcut' => $shortcut,

        'is_link' => is_link($shortcut),
        'link_target' => readlink($shortcut),

        'test_file' => $shortcut . '/test.txt',
        'test_exists' => file_exists($shortcut . '/test.txt'),
        'test_content' => file_exists($shortcut . '/test.txt')
            ? file_get_contents($shortcut . '/test.txt')
            : null,

        'realpath' => realpath($shortcut . '/test.txt'),
    ]);
});
