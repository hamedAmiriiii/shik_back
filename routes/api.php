<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;

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

Route::name('geo.')->prefix('geo')->group(function (){
    Route::get('cities' , [\App\Http\Controllers\CityController::class,'index']);
    Route::get('states' , [\App\Http\Controllers\StateController::class,'index']);
});

Route::name('resetPassword.')->prefix('reset-password')->group(function () {
    Route::post('', [\App\Http\Controllers\Auth\AuthController::class, 'resetPassword']);
});

Route::name('confirmationCode.')->prefix('confirmation-code')->group(function () {
    Route::post('create', [\App\Http\Controllers\ConfirmationCode\ConfirmationCodeController::class, 'store']);
    Route::post('check', [\App\Http\Controllers\ConfirmationCode\ConfirmationCodeController::class, 'check']);
});


Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ReportController::class, 'index']);
});

Route::resource('expenses', \App\Http\Controllers\ExpenseController::class);

Route::prefix('purchased-products')->name('purchased-products.')->group(function () {
    Route::get('/', [\App\Http\Controllers\PurchasedProductController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\PurchasedProductController::class, 'store']);
    Route::get('/{purchasedProduct}', [\App\Http\Controllers\PurchasedProductController::class, 'show']);
    Route::put('/{purchasedProduct}', [\App\Http\Controllers\PurchasedProductController::class, 'update']);
    Route::delete('/{purchasedProduct}', [\App\Http\Controllers\PurchasedProductController::class, 'destroy']);
});


Route::resource("product", ProductController::class);
Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::name('admin.')->prefix('admin')->group(function () {
        
        // اضافه کردن روت‌های مدیریت محصولات
        

        Route::resource("atelier", \App\Http\Controllers\Admin\AtelierController::class);
        Route::post("/atelier/confirm/{atelier}", [\App\Http\Controllers\Admin\AtelierController::class, "confirm"]);

        Route::resource("cameraman", \App\Http\Controllers\Admin\CameramanController::class);
        Route::post("/cameraman/confirm/{cameraman}", [\App\Http\Controllers\Admin\CameramanController::class, "confirm"]);
        Route::post("/cameraman/reset-password/{cameraman}", [\App\Http\Controllers\Admin\CameramanController::class, "resetPassword"]);

        Route::middleware(['city.access'])->group(function () {
            Route::resource("ceremony", \App\Http\Controllers\Admin\CeremonyController::class);
            Route::post("/ceremony/confirm/{ceremony}", [\App\Http\Controllers\Admin\CeremonyController::class, "confirm"]);

            Route::resource("talar", \App\Http\Controllers\Admin\TalarController::class);
            Route::post("/talar/confirm/{talar}", [\App\Http\Controllers\Admin\TalarController::class, "confirm"]);

            Route::resource("garden", \App\Http\Controllers\Admin\GardenController::class);
            Route::post("/garden/confirm/{garden}", [\App\Http\Controllers\Admin\GardenController::class, "confirm"]);

            // مدیریت مرخصی‌ها
            Route::resource("leave", \App\Http\Controllers\Admin\LeaveController::class)->only(["index", "update", "show"]);
            Route::post("/leave/confirm/{leave}", [\App\Http\Controllers\Admin\LeaveController::class, "confirm"]);
        });

        Route::resource("/log-sms", \App\Http\Controllers\Admin\LogSmsController::class)->only("index");
    });

    Route::name('cameraman.')->prefix('cameraman')->group(function () {
        Route::resource("leave", \App\Http\Controllers\Cameraman\LeaveController::class);
        Route::resource("ceremony", \App\Http\Controllers\Cameraman\CeremonyController::class)->only(['index']);
        Route::post("/profile/reset-password", [\App\Http\Controllers\Cameraman\ProfileController::class, "resetPassword"]);
    });

    Route::name('atelier.')->prefix('atelier')->group(function () {
        Route::resource("talar", \App\Http\Controllers\Atelier\TalarController::class)->only("index");
        Route::resource("garden", \App\Http\Controllers\Atelier\GardenController::class)->only("index");
        Route::resource("cameraman", \App\Http\Controllers\Atelier\CameramanController::class)->only("index");
        Route::resource("ceremony", \App\Http\Controllers\Atelier\CeremonyController::class);
        Route::post("/profile/reset-password", [\App\Http\Controllers\Atelier\ProfileController::class, "resetPassword"]);
    });
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
