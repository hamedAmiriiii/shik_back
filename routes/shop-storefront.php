<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\CustomerRegisterController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShopStorefrontController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ویترین آنلاین هر فروشگاه — پیشوند: api/{shop}
| مثال: GET /api/milito ، POST /api/milito/customer/login
| {shop} همان code فروشگاه در جدول ateliers است.
|--------------------------------------------------------------------------
*/

Route::pattern('shop', '[a-zA-Z0-9][a-zA-Z0-9_-]*');

Route::prefix('{shop}')
    ->middleware('shop.from.route')
    ->name('shop.')
    ->group(function () {
        Route::get('/', [ShopStorefrontController::class, 'show'])->name('show');

        Route::prefix('customer-register')->name('customer-register.')->group(function () {
            Route::post('send-code', [CustomerRegisterController::class, 'sendVerificationCode']);
            Route::post('verify', [CustomerRegisterController::class, 'verifyAndRegister']);
            Route::post('check-phone', [CustomerRegisterController::class, 'checkPhone']);
            Route::post('login', [CustomerRegisterController::class, 'verifyAndLogin']);
        });

        Route::post('customer/login', [CustomerRegisterController::class, 'verifyAndLogin'])->name('customer.login');

        Route::middleware('auth:sanctum')->post('customer-register/logout', [CustomerRegisterController::class, 'logout'])
            ->name('customer-register.logout');

        Route::get('product', [ProductController::class, 'index'])->name('product.index');
        Route::get('product/best-selling', [ProductController::class, 'bestSelling'])->name('product.best-selling');
        Route::get('product/{product}', [ProductController::class, 'show'])->where('product', '[0-9]+')->name('product.show');

        Route::get('category', [CategoryController::class, 'index'])->name('category.index');
        Route::get('category/{category}', [CategoryController::class, 'show'])->where('category', '[0-9]+')->name('category.show');
        Route::get('category/{category}/children', [CategoryController::class, 'children'])->where('category', '[0-9]+')->name('category.children');
        Route::get('category/{category}/products', [CategoryController::class, 'products'])->where('category', '[0-9]+')->name('category.products');

        Route::middleware('auth:sanctum')->prefix('cart')->name('cart.')->group(function () {
            Route::get('/', [CartController::class, 'show']);
            Route::post('/', [CartController::class, 'store']);
            Route::put('/shipping-info', [CartController::class, 'updateShippingInfo']);
            Route::post('/set-address', [CartController::class, 'setAddress']);
            Route::get('/default-address', [CartController::class, 'getDefaultAddress']);
            Route::post('/complete-order', [CartController::class, 'completeOrder']);
            Route::delete('/', [CartController::class, 'destroy']);
            Route::get('/my-orders', [CartController::class, 'myOrders']);
            Route::get('/my-orders/{cart}', [CartController::class, 'showOrder']);
        });

        Route::middleware('auth:sanctum')->prefix('customer-addresses')->name('customer-addresses.')->group(function () {
            Route::get('/', [CustomerAddressController::class, 'index']);
            Route::post('/', [CustomerAddressController::class, 'store']);
            Route::get('/{address}', [CustomerAddressController::class, 'show']);
            Route::put('/{address}', [CustomerAddressController::class, 'update']);
            Route::delete('/{address}', [CustomerAddressController::class, 'destroy']);
            Route::post('/{address}/set-default', [CustomerAddressController::class, 'setDefault']);
        });
    });
