<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController as AdminProducts;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\ProductController;
use App\Http\Controllers\Shop\StoreController;
use App\Http\Controllers\Shop\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Public shop API (used by the React website)
*/
Route::get('store', [StoreController::class, 'store']);
Route::get('home', [StoreController::class, 'home']);
Route::get('pages/{key}', [StoreController::class, 'page'])->whereAlpha('key');
Route::get('products', [ProductController::class, 'index']);
Route::get('products/{slug}', [ProductController::class, 'show'])->where('slug', '[A-Za-z0-9\-_]+');
Route::post('products/{slug}/reviews', [ProductController::class, 'review'])->where('slug', '[A-Za-z0-9\-_]+')->middleware('throttle:reviews');

Route::post('cart/quote', [CheckoutController::class, 'quote'])->middleware('throttle:quote');
Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:checkout');
Route::post('orders/{number}/pay', [CheckoutController::class, 'pay'])->middleware('throttle:checkout');
Route::get('orders/{number}', [CheckoutController::class, 'show'])->middleware('throttle:quote');
Route::post('track', [CheckoutController::class, 'track'])->middleware('throttle:track');

Route::post('webhooks/stripe', StripeWebhookController::class)->middleware('throttle:webhooks');

/*
| Admin panel API: session cookie (Sanctum SPA) + CSRF. Roles: admin, manager.
*/
Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'user']);
        Route::put('me/password', [AuthController::class, 'password']);
        Route::get('dashboard', DashboardController::class);

        // Products
        Route::get('products', [AdminProducts::class, 'index']);
        Route::post('products', [AdminProducts::class, 'store']);
        Route::get('products/{id}', [AdminProducts::class, 'show'])->whereNumber('id');
        Route::put('products/{id}', [AdminProducts::class, 'update'])->whereNumber('id');
        Route::delete('products/{id}', [AdminProducts::class, 'destroy'])->whereNumber('id');
        Route::post('products/{id}/restore', [AdminProducts::class, 'restore'])->whereNumber('id');
        Route::post('products/{id}/duplicate', [AdminProducts::class, 'duplicate'])->whereNumber('id');
        Route::post('products/{id}/images', [AdminProducts::class, 'uploadImages'])->whereNumber('id');
        Route::put('products/{id}/images', [AdminProducts::class, 'updateImages'])->whereNumber('id');
        Route::delete('products/{id}/images/{imageId}', [AdminProducts::class, 'deleteImage'])->whereNumber(['id', 'imageId']);

        // Stock & prices
        Route::get('inventory', [InventoryController::class, 'index']);
        Route::put('inventory', [InventoryController::class, 'bulk']);
        Route::post('inventory/{id}/adjust', [InventoryController::class, 'adjust'])->whereNumber('id');
        Route::get('inventory/movements', [InventoryController::class, 'movements']);

        // Catalog
        Route::get('brands', [CatalogController::class, 'brands']);
        Route::post('brands', [CatalogController::class, 'saveBrand']);
        Route::post('brands/{id}', [CatalogController::class, 'saveBrand'])->whereNumber('id'); // multipart update
        Route::delete('brands/{id}', [CatalogController::class, 'deleteBrand'])->whereNumber('id');
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::post('categories', [CatalogController::class, 'saveCategory']);
        Route::post('categories/{id}', [CatalogController::class, 'saveCategory'])->whereNumber('id');
        Route::delete('categories/{id}', [CatalogController::class, 'deleteCategory'])->whereNumber('id');
        Route::get('reviews', [CatalogController::class, 'reviews']);
        Route::put('reviews/{id}', [CatalogController::class, 'updateReview'])->whereNumber('id');
        Route::delete('reviews/{id}', [CatalogController::class, 'deleteReview'])->whereNumber('id');

        // Orders & customers
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/export', [OrderController::class, 'export']);
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
        Route::put('orders/{id}/status', [OrderController::class, 'status'])->whereNumber('id');
        Route::put('orders/{id}/tracking', [OrderController::class, 'tracking'])->whereNumber('id');
        Route::post('orders/{id}/notes', [OrderController::class, 'note'])->whereNumber('id');
        Route::get('customers', [CatalogController::class, 'customers']);
        Route::get('customers/{id}', [CatalogController::class, 'customer'])->whereNumber('id');
        Route::put('customers/{id}', [CatalogController::class, 'updateCustomer'])->whereNumber('id');

        // Website content
        Route::get('banners', [SettingsController::class, 'banners']);
        Route::post('banners', [SettingsController::class, 'saveBanner']);
        Route::post('banners/{id}', [SettingsController::class, 'saveBanner'])->whereNumber('id');
        Route::delete('banners/{id}', [SettingsController::class, 'deleteBanner'])->whereNumber('id');
        Route::get('announcements', [SettingsController::class, 'announcements']);
        Route::post('announcements', [SettingsController::class, 'saveAnnouncement']);
        Route::put('announcements/{id}', [SettingsController::class, 'saveAnnouncement'])->whereNumber('id');
        Route::delete('announcements/{id}', [SettingsController::class, 'deleteAnnouncement'])->whereNumber('id');
        Route::get('home-sections', [SettingsController::class, 'sections']);
        Route::post('home-sections', [SettingsController::class, 'saveSection']);
        Route::put('home-sections/{id}', [SettingsController::class, 'saveSection'])->whereNumber('id');
        Route::delete('home-sections/{id}', [SettingsController::class, 'deleteSection'])->whereNumber('id');
        Route::get('coupons', [CatalogController::class, 'coupons']);
        Route::post('coupons', [CatalogController::class, 'saveCoupon']);
        Route::put('coupons/{id}', [CatalogController::class, 'saveCoupon'])->whereNumber('id');
        Route::delete('coupons/{id}', [CatalogController::class, 'deleteCoupon'])->whereNumber('id');

        // Admin role only: refunds, store settings, payment methods, staff
        Route::middleware('staff:admin')->group(function () {
            Route::post('orders/{id}/refund', [OrderController::class, 'refund'])->whereNumber('id');
            Route::get('settings', [SettingsController::class, 'settings']);
            Route::post('settings', [SettingsController::class, 'updateSettings']); // multipart (logo)
            Route::get('payment-methods', [SettingsController::class, 'paymentMethods']);
            Route::put('payment-methods/{id}', [SettingsController::class, 'updatePaymentMethod'])->whereNumber('id');
            Route::get('staff', [SettingsController::class, 'staff']);
            Route::post('staff', [SettingsController::class, 'saveStaff']);
            Route::put('staff/{id}', [SettingsController::class, 'saveStaff'])->whereNumber('id');
            Route::delete('staff/{id}', [SettingsController::class, 'deleteStaff'])->whereNumber('id');
        });
    });
});
