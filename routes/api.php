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
    Route::post('register/send-phone-code', [\App\Http\Controllers\Auth\AuthController::class, 'sendRegistrationPhoneCode']);
    Route::post('register', [\App\Http\Controllers\Auth\AuthController::class, 'register']);
    Route::post('login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
    Route::get('atelier', [\App\Http\Controllers\Auth\AtelierController::class, 'index']);
});

Route::name('geo.')->prefix('geo')->group(function (){
    Route::get('cities' , [\App\Http\Controllers\CityController::class,'index']);
    Route::get('states' , [\App\Http\Controllers\StateController::class,'index']);
});

Route::name('resetPassword.')->prefix('reset-password')->group(function () {
    Route::post('send-code', [\App\Http\Controllers\Auth\AuthController::class, 'sendForgotPasswordCode']);
    Route::post('confirm', [\App\Http\Controllers\Auth\AuthController::class, 'confirmForgotPassword']);
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
Route::get("product/best-selling", [ProductController::class, 'bestSelling']);
// این route باید بعد از route های خاص (مثل best-selling) و قبل از route های authenticated باشد
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
    Route::get('/installment-credit', [\App\Http\Controllers\PurchasedProductController::class, 'getInstallmentCredit']);
    Route::post('/calculate-installments', [\App\Http\Controllers\PurchasedProductController::class, 'calculateInstallments']);
    Route::get('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'show']);
    Route::put('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'update']);
    Route::delete('/{purchase}', [\App\Http\Controllers\PurchasedProductController::class, 'destroy']);
    Route::delete('/{purchase}/items/{purchasedProduct}', [\App\Http\Controllers\PurchasedProductController::class, 'returnItem']);
});

Route::prefix('customers')->name('customers.')->group(function () {
    Route::post('/register', [\App\Http\Controllers\CustomerController::class, 'registerUserShiksho']);
    Route::get('/', [\App\Http\Controllers\CustomerController::class, 'index']);
    Route::get('/{phone}', [\App\Http\Controllers\CustomerController::class, 'show']);
});

Route::prefix('returned-products')->name('returned-products.')->group(function () {
    Route::post('/', [\App\Http\Controllers\ReturnedProductController::class, 'store']);
    Route::get('/', [\App\Http\Controllers\ReturnedProductController::class, 'index']);
});

// Manufacturer routes - public GET, authenticated POST/PUT/DELETE
Route::get('manufacturers', [\App\Http\Controllers\ManufacturerController::class, 'index']);
Route::get('manufacturers/{manufacturer}', [\App\Http\Controllers\ManufacturerController::class, 'show']);
Route::get('manufacturers/report/sales', [\App\Http\Controllers\ManufacturerController::class, 'salesReport']);

Route::group(['middleware' => ['auth:sanctum']], function () {
    // Store/Shop related routes - require authentication
    Route::get('expenses-statistics', [\App\Http\Controllers\ExpenseController::class, 'statistics']);
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class);
    
    // Invoice routes - require authentication
    Route::resource('invoices', \App\Http\Controllers\InvoiceController::class);
    
    // SMS Logs routes - require authentication
    Route::prefix('sms-logs')->name('sms-logs.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\LogSmsController::class, 'index']);
    });
    
    // Shop SMS Logs routes - require authentication (برای فروشگاه)
    Route::prefix('shop-sms-logs')->name('shop-sms-logs.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopSmsLogController::class, 'index']);
        Route::get('/{shopSmsLog}', [\App\Http\Controllers\ShopSmsLogController::class, 'show']);
    });

    Route::get('shop-access', [\App\Http\Controllers\ShopAccessController::class, 'show']);

    Route::get('dashboard/summary', [\App\Http\Controllers\DashboardController::class, 'summary']);
    Route::get('dashboard/sales-by-day', [\App\Http\Controllers\DashboardController::class, 'salesByDay']);
    Route::get('dashboard/daily-sales', [\App\Http\Controllers\DashboardController::class, 'dailySales']);

    Route::get('returned-products/grid', [\App\Http\Controllers\ReturnedProductController::class, 'grid']);
    Route::get('purchase-item-returns/grid', [\App\Http\Controllers\PurchaseItemReturnController::class, 'grid']);

    Route::get('daily-reconciliations', [\App\Http\Controllers\DailyShopReconciliationController::class, 'index']);
    Route::post('daily-reconciliations', [\App\Http\Controllers\DailyShopReconciliationController::class, 'store']);
    Route::get('daily-reconciliations/{date}', [\App\Http\Controllers\DailyShopReconciliationController::class, 'show'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

    Route::prefix('shop-sms-quota')->name('shop-sms-quota.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ShopSmsQuotaController::class, 'show']);
        Route::post('/estimate', [\App\Http\Controllers\ShopSmsQuotaController::class, 'estimate']);
    });
    
    // Manufacturer routes - require authentication (POST/PUT/DELETE)
    Route::post('manufacturers', [\App\Http\Controllers\ManufacturerController::class, 'store']);
    Route::put('manufacturers/{manufacturer}', [\App\Http\Controllers\ManufacturerController::class, 'update']);
    Route::delete('manufacturers/{manufacturer}', [\App\Http\Controllers\ManufacturerController::class, 'destroy']);

    // Broadcast message to selected customers
    Route::get('customer-broadcast/list', [\App\Http\Controllers\CustomerController::class, 'getCustomersForBroadcast']);
    Route::post('customer-broadcast/message', [\App\Http\Controllers\CustomerController::class, 'broadcastMessage']);

    // Product routes that require authentication
    Route::post("product", [ProductController::class, 'store']);
    Route::put("product/{product}", [ProductController::class, 'update'])->where('product', '[0-9]+')->name('product.update');
    Route::delete("product/{product}", [ProductController::class, 'destroy'])->where('product', '[0-9]+');
    Route::delete("product/{product}/image/{imageId}", [ProductController::class, 'deleteImage']);
    Route::post("products/apply-discount", [ProductController::class, 'applyDiscount']);

    // Category routes that require authentication
    Route::post("category", [CategoryController::class, 'store']);
    Route::put("category/{category}", [CategoryController::class, 'update']);
    Route::delete("category/{category}", [CategoryController::class, 'destroy']);

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [\App\Http\Controllers\SettingController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\SettingController::class, 'store']);
        Route::get('/loyalty-credit', [\App\Http\Controllers\SettingController::class, 'getLoyaltyCreditStatus']);
        Route::post('/loyalty-credit/toggle', [\App\Http\Controllers\SettingController::class, 'toggleLoyaltyCredit']);
        Route::get('/loyalty-credit-tiers', [\App\Http\Controllers\SettingController::class, 'getLoyaltyCreditTiers']);
        Route::post('/loyalty-credit-tiers', [\App\Http\Controllers\SettingController::class, 'setLoyaltyCreditTiers']);
        Route::put('/loyalty-credit-tiers', [\App\Http\Controllers\SettingController::class, 'setLoyaltyCreditTiers']);
        Route::get('/credit-expiry-days', [\App\Http\Controllers\SettingController::class, 'getCreditExpiryDays']);
        Route::post('/credit-expiry-days', [\App\Http\Controllers\SettingController::class, 'setCreditExpiryDays']);
        Route::put('/credit-expiry-days', [\App\Http\Controllers\SettingController::class, 'setCreditExpiryDays']);
        Route::get('/installment-interest-rate', [\App\Http\Controllers\SettingController::class, 'getInstallmentInterestRate']);
        Route::post('/installment-interest-rate', [\App\Http\Controllers\SettingController::class, 'setInstallmentInterestRate']);
        Route::put('/installment-interest-rate', [\App\Http\Controllers\SettingController::class, 'setInstallmentInterestRate']);
        $reservedSettingKeys = 'loyalty-credit-tiers|loyalty-credit|credit-expiry-days|installment-interest-rate';
        Route::get('/{key}', [\App\Http\Controllers\SettingController::class, 'show'])
            ->where('key', '^(?!'.$reservedSettingKeys.').+');
        Route::put('/{key}', [\App\Http\Controllers\SettingController::class, 'update'])
            ->where('key', '^(?!'.$reservedSettingKeys.').+');
    });

    // Installment routes - requires admin authentication
    Route::prefix('installments')->name('installments.')->group(function () {
        Route::get('/by-phone', [\App\Http\Controllers\InstallmentController::class, 'getByPhone']);
        Route::get('/unpaid', [\App\Http\Controllers\InstallmentController::class, 'unpaid']);
    });

    // Installment Credit CRUD routes - requires admin authentication
    Route::prefix('installment-credits')->name('installment-credits.')->group(function () {
        Route::get('/', [\App\Http\Controllers\InstallmentCreditController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\InstallmentCreditController::class, 'store']);
        Route::get('/{phone}', [\App\Http\Controllers\InstallmentCreditController::class, 'show']);
        Route::put('/{phone}', [\App\Http\Controllers\InstallmentCreditController::class, 'update']);
        Route::delete('/{phone}', [\App\Http\Controllers\InstallmentCreditController::class, 'destroy']);
    });

    // Purchase installment routes - requires admin authentication
    Route::prefix('purchased-products')->name('purchased-products.installments.')->group(function () {
        Route::get('/{purchase}/installments', [\App\Http\Controllers\InstallmentController::class, 'index']);
        Route::post('/{purchase}/installments/{installment}/pay', [\App\Http\Controllers\InstallmentController::class, 'pay']);
    });

    // Financial Report routes - requires authentication
    Route::prefix('financial-report')->name('financial-report.')->group(function () {
        Route::get('/monthly', [\App\Http\Controllers\FinancialReportController::class, 'monthlyReport']);
    });

    // Admin routes
    Route::name('admin.')->prefix('admin')->group(function () {
        
        // اضافه کردن روت‌های مدیریت محصولات
        

        Route::resource("atelier", \App\Http\Controllers\Admin\AtelierController::class);
        Route::post("/atelier/confirm/{atelier}", [\App\Http\Controllers\Admin\AtelierController::class, "confirm"]);

        Route::get('shops', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'index']);
        Route::put('shops/{atelier}', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'update']);
        Route::get('shop-sms-quota', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'index']);
        Route::get('shop-sms-quota/{atelier}', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'show']);
        Route::put('shop-sms-quota/{atelier}', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'update']);
        Route::post('shop-sms-quota/{atelier}/charge', [\App\Http\Controllers\Admin\ShopSmsQuotaController::class, 'charge']);

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
    Route::post('/set-address', [\App\Http\Controllers\CartController::class, 'setAddress']);
    Route::get('/default-address', [\App\Http\Controllers\CartController::class, 'getDefaultAddress']);
    Route::post('/complete-order', [\App\Http\Controllers\CartController::class, 'completeOrder']);
    Route::delete('/', [\App\Http\Controllers\CartController::class, 'destroy']);
    
    // Customer orders routes (only their own orders)
    Route::get('/my-orders', [\App\Http\Controllers\CartController::class, 'myOrders']);
    Route::get('/my-orders/{cart}', [\App\Http\Controllers\CartController::class, 'showOrder']);
});

// Customer Addresses routes - requires customer authentication
Route::middleware('auth:sanctum')->prefix('customer-addresses')->name('customer-addresses.')->group(function () {
    Route::get('/', [\App\Http\Controllers\CustomerAddressController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\CustomerAddressController::class, 'store']);
    Route::get('/{address}', [\App\Http\Controllers\CustomerAddressController::class, 'show']);
    Route::put('/{address}', [\App\Http\Controllers\CustomerAddressController::class, 'update']);
    Route::delete('/{address}', [\App\Http\Controllers\CustomerAddressController::class, 'destroy']);
    Route::post('/{address}/set-default', [\App\Http\Controllers\CustomerAddressController::class, 'setDefault']);
});

// Order routes - requires admin authentication (for managing online orders)
Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [\App\Http\Controllers\OrderController::class, 'index']);
    Route::get('/count/completed', [\App\Http\Controllers\OrderController::class, 'completedOrdersCount']);
    Route::get('/{cart}', [\App\Http\Controllers\OrderController::class, 'show']);
    Route::put('/{cart}/status', [\App\Http\Controllers\OrderController::class, 'updateStatus']);
});

// ویترین آنلاین — api/{shop}/... (باید بعد از مسیرهای ثابت باشد)
require __DIR__.'/shop-storefront.php';
