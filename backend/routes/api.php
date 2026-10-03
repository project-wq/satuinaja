<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\MarketplaceWebhookController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\StorefrontController;
use App\Http\Controllers\Api\V1\VariantController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Keamanan: rate limit berlapis, auth Sanctum cookie, tenant scope di model.
*/

// ---------- Publik (rate limit ketat) ----------
Route::prefix('v1')->middleware('throttle:api-public')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);

    // Spesifikasi platform & field kredensial (dipakai form channel)
    Route::get('platforms', [ChannelController::class, 'platforms']);

    // Storefront publik
    Route::get('shops', [StorefrontController::class, 'index']);
    Route::get('shops/{slug}', [StorefrontController::class, 'show']);
    Route::get('shops/{slug}/products/{productSlug}', [StorefrontController::class, 'product']);

    // Utilitas publik
    Route::get('shipping/cities', [ShippingController::class, 'cities']);
    Route::post('shipping/cost', [ShippingController::class, 'cost']);
    Route::get('shipping/track', [ShippingController::class, 'track']);

    // Checkout & status order publik
    Route::post('checkout/preview', [CheckoutController::class, 'preview']);
    Route::post('checkout', [CheckoutController::class, 'store']);
    Route::get('orders/track/{orderNo}', [CheckoutController::class, 'track']);
});

// ---------- Webhook (signature-verified, tanpa auth) ----------
Route::prefix('v1/webhooks')->middleware('throttle:api-public')->group(function () {
    Route::post('midtrans', [WebhookController::class, 'midtrans']);

    // Fase 7: webhook order marketplace (HMAC atas raw body).
    Route::post('shopee/{channel}', [MarketplaceWebhookController::class, 'shopee']);
    Route::post('tokopedia/{channel}', [MarketplaceWebhookController::class, 'tokopedia']);
});

// ---------- Merchant (butuh login + rate limit longgar) ----------
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api-auth'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/publish', [ProductController::class, 'publish']);
    Route::put('products/{product}/variants', [VariantController::class, 'sync']);
    Route::put('products/{product}/variants/{variant}', [VariantController::class, 'update']);
    Route::delete('products/{product}/variants/{variant}', [VariantController::class, 'destroy']);

    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels', [ChannelController::class, 'store']);
    Route::put('channels/{channel}', [ChannelController::class, 'update']);
    Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);
    Route::post('channels/{channel}/verify', [ChannelController::class, 'verify']);
    Route::get('channels/{channel}/logs', [ChannelController::class, 'logs']);

    Route::post('ai/caption', [AiController::class, 'caption']);
    Route::post('ai/preview', [AiController::class, 'preview']);

    // Langganan & batas plan
    Route::get('billing', [BillingController::class, 'index']);
    Route::post('billing/subscribe', [BillingController::class, 'subscribe']);

    // Admin (Fase 4)
    Route::prefix('admin')->group(function () {
        Route::get('stats', [AdminController::class, 'stats']);
        Route::get('merchants', [AdminController::class, 'merchants']);
        Route::put('merchants/{merchant}/status', [AdminController::class, 'setMerchantStatus']);
        Route::put('merchants/{merchant}/plan', [AdminController::class, 'setPlan']);

        // Fase 5: konfigurasi fee + kelola withdraw + refund
        Route::get('settings', [AdminController::class, 'settings']);
        Route::put('settings', [AdminController::class, 'updateSettings']);
        Route::get('withdrawals', [AdminController::class, 'withdrawals']);
        Route::put('withdrawals/{withdrawal}', [AdminController::class, 'processWithdrawal']);

        // Fase 6: putuskan refund
        Route::get('refunds', [RefundController::class, 'adminIndex']);
        Route::put('refunds/{refund}', [RefundController::class, 'process']);

        // Fase 7: status payment gateway (KYC Midtrans produksi)
        Route::get('payment-gateway', [AdminController::class, 'paymentGateway']);
        Route::put('payment-gateway', [AdminController::class, 'updatePaymentGateway']);
    });

    // Fase 5: saldo seller + withdraw (merchant login)
    Route::get('balance', [BalanceController::class, 'index']);
    Route::get('balance/transactions', [BalanceController::class, 'transactions']);
    Route::post('balance/withdraw', [BalanceController::class, 'withdraw']);
    Route::get('balance/withdrawals', [BalanceController::class, 'withdrawals']);

    Route::get('orders', [CheckoutController::class, 'index']);
    Route::get('orders/{order}', [CheckoutController::class, 'show']);
    Route::put('orders/{order}/ship', [CheckoutController::class, 'ship']);

    // Fase 6: refund oleh seller
    Route::get('refunds', [RefundController::class, 'index']);
    Route::post('refunds', [RefundController::class, 'store']);

    // Fase 6: laporan penjualan
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/sales/export', [ReportController::class, 'export']);

    // Fase 6: notifikasi in-app
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::put('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
});
