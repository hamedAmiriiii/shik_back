<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::name('auth.')->prefix('auth')->group(function () {
    Route::post('register', [\App\Http\Controllers\AuthController::class, 'register']);
    Route::post('login', [\App\Http\Controllers\AuthController::class, 'login']);
});

Route::name('confirmationCode.')->prefix('confirmation-code')->group(function () {
    Route::post('create', [\App\Http\Controllers\ConfirmationCodeController::class, 'store']);
    Route::post('check', [\App\Http\Controllers\ConfirmationCodeController::class, 'check']);
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::name('admin.')->prefix('admin')->group(function () {
        Route::resource("atelier",\App\Http\Controllers\Admin\AtelierController::class);
        Route::resource("cameraman",\App\Http\Controllers\Admin\CameramanController::class);
        Route::resource("ceremony",\App\Http\Controllers\Admin\AtelierController::class);
    });

    Route::name('cameraman.')->prefix('cameraman')->group(function () {
        Route::resource("leave",\App\Http\Controllers\Cameraman\LeaveController::class);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
