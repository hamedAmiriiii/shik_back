<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;

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

// Customer registration routes - no authentication required
Route::prefix('customer-register')->name('customer-register.')->group(function () {
    Route::post('send-code', [\App\Http\Controllers\CustomerRegisterController::class, 'sendVerificationCode']);
    Route::post('verify', [\App\Http\Controllers\CustomerRegisterController::class, 'verifyAndRegister']);
    Route::post('check-phone', [\App\Http\Controllers\CustomerRegisterController::class, 'checkPhone']);
    Route::post('login', [\App\Http\Controllers\CustomerRegisterController::class, 'verifyAndLogin']);
});

// Customer login route (shorter path)
Route::post('customer/login', [\App\Http\Controllers\CustomerRegisterController::class, 'verifyAndLogin']);


Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [\App\Http\Controllers\ReportController::class, 'index']);
});

// Public routes - no authentication required
Route::get("product-all", [ProductController::class, 'getAll']);
Route::get("product", [ProductController::class, 'index']);
Route::get("product/{product}", [ProductController::class, 'show']);

// Category routes - public GET, authenticated POST/PUT/DELETE
Route::get("category", [CategoryController::class, 'index']);
Route::get("category-all", [CategoryController::class, 'getAll']);
Route::get("category/{category}", [CategoryController::class, 'show']);
Route::get("category/{category}/children", [CategoryController::class, 'children']);
Route::get("category/{category}/products", [CategoryController::class, 'products']);

Route::prefix('purchased-products')->name('purchased-products.')->group(function () {
    Route::get('/', [\App\Http\Controllers\PurchasedProductController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\PurchasedProductController::class, 'store']);
    Route::get('/credit', [\App\Http\Controllers\PurchasedProductController::class, 'getCreditByPhone']);
    Route::get('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'show']);
    Route::put('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'update']);
    Route::delete('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'destroy']);
});

Route::prefix('customers')->name('customers.')->group(function () {
    Route::get('/', [\App\Http\Controllers\CustomerController::class, 'index']);
    Route::get('/{phone}', [\App\Http\Controllers\CustomerController::class, 'show']);
});

Route::prefix('returned-products')->name('returned-products.')->group(function () {
    Route::post('/', [\App\Http\Controllers\ReturnedProductController::class, 'store']);
    Route::get('/', [\App\Http\Controllers\ReturnedProductController::class, 'index']);
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    // Store/Shop related routes - require authentication
    Route::get('expenses-statistics', [\App\Http\Controllers\ExpenseController::class, 'statistics']);
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class);

    // Broadcast message to selected customers
    Route::get('customer-broadcast/list', [\App\Http\Controllers\CustomerController::class, 'getCustomersForBroadcast']);
    Route::post('customer-broadcast/message', [\App\Http\Controllers\CustomerController::class, 'broadcastMessage']);

    // Product routes that require authentication
    Route::post("product", [ProductController::class, 'store']);
    Route::put("product/{product}", [ProductController::class, 'update']);
    Route::delete("product/{product}", [ProductController::class, 'destroy']);
    Route::delete("product/{product}/image/{imageId}", [ProductController::class, 'deleteImage']);
    Route::post("products/apply-discount", [ProductController::class, 'applyDiscount']);

    // Category routes that require authentication
    Route::post("category", [CategoryController::class, 'store']);
    Route::put("category/{category}", [CategoryController::class, 'update']);
    Route::delete("category/{category}", [CategoryController::class, 'destroy']);

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [\App\Http\Controllers\SettingController::class, 'index']);
        Route::get('/loyalty-credit', [\App\Http\Controllers\SettingController::class, 'getLoyaltyCreditStatus']);
        Route::post('/loyalty-credit/toggle', [\App\Http\Controllers\SettingController::class, 'toggleLoyaltyCredit']);
        Route::get('/{key}', [\App\Http\Controllers\SettingController::class, 'show']);
        Route::put('/{key}', [\App\Http\Controllers\SettingController::class, 'update']);
    });

    // Admin routes
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

// Customer logout route - requires authentication
Route::middleware('auth:sanctum')->post('customer-register/logout', [\App\Http\Controllers\CustomerRegisterController::class, 'logout']);

// Cart routes - requires customer authentication
Route::middleware('auth:sanctum')->prefix('cart')->name('cart.')->group(function () {
    Route::get('/', [\App\Http\Controllers\CartController::class, 'show']);
    Route::post('/', [\App\Http\Controllers\CartController::class, 'store']);
    Route::put('/shipping-info', [\App\Http\Controllers\CartController::class, 'updateShippingInfo']);
    Route::get('/default-address', [\App\Http\Controllers\CartController::class, 'getDefaultAddress']);
    Route::post('/complete-order', [\App\Http\Controllers\CartController::class, 'completeOrder']);
    Route::delete('/', [\App\Http\Controllers\CartController::class, 'destroy']);
});

// Order routes - requires admin authentication (for managing online orders)
Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [\App\Http\Controllers\OrderController::class, 'index']);
    Route::get('/{cart}', [\App\Http\Controllers\OrderController::class, 'show']);
    Route::put('/{cart}/status', [\App\Http\Controllers\OrderController::class, 'updateStatus']);
});
