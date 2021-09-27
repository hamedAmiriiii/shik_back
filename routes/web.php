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

/*Route::get('/test', function () {
    $target = '/home2/kermanph/atelier/storage/app/public';
    $shortcut = '/home2/kermanph/public_html/api/storage';
    symlink($target, $shortcut);
    //\Illuminate\Support\Facades\Artisan::call('storage:link');
});*/
