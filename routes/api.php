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
    Route::post('register', [\App\Http\Controllers\Auth\AuthController::class, 'register']);
    Route::post('login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
    Route::get('atelier', [\App\Http\Controllers\Auth\AtelierController::class, 'index']);
});

Route::name('confirmationCode.')->prefix('confirmation-code')->group(function () {
    Route::post('create', [\App\Http\Controllers\ConfirmationCode\ConfirmationCodeController::class, 'store']);
    Route::post('check', [\App\Http\Controllers\ConfirmationCode\ConfirmationCodeController::class, 'check']);
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::name('admin.')->prefix('admin')->group(function () {

        Route::resource("atelier", \App\Http\Controllers\Admin\AtelierController::class);
        Route::post("/atelier/confirm/{atelier}", [\App\Http\Controllers\Admin\AtelierController::class, "confirm"]);

        Route::resource("cameraman", \App\Http\Controllers\Admin\CameramanController::class);
        Route::post("/cameraman/confirm/{cameraman}", [\App\Http\Controllers\Admin\CameramanController::class, "confirm"]);

        Route::resource("ceremony", \App\Http\Controllers\Admin\CeremonyController::class);
        Route::post("/ceremony/confirm/{ceremony}", [\App\Http\Controllers\Admin\CeremonyController::class, "confirm"]);

        Route::resource("talar",\App\Http\Controllers\Admin\TalarController::class);

        Route::resource("garden",\App\Http\Controllers\Admin\GardenController::class);

        Route::resource("leave",\App\Http\Controllers\Admin\LeaveController::class);
        Route::post("/leave/confirm/{leave}", [\App\Http\Controllers\Admin\LeaveController::class, "confirm"]);
    });

    Route::name('cameraman.')->prefix('cameraman')->group(function () {
        Route::resource("leave", \App\Http\Controllers\Cameraman\LeaveController::class);
    });

    Route::name('atelier.')->prefix('atelier')->group(function () {
        Route::resource("talar", \App\Http\Controllers\Atelier\TalarController::class)->only("index");
        Route::resource("garden", \App\Http\Controllers\Atelier\GardenController::class)->only("index");
        Route::resource("cameraman", \App\Http\Controllers\Atelier\CameramanController::class)->only("index");
        Route::resource("ceremony", \App\Http\Controllers\Atelier\CeremonyController::class);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
