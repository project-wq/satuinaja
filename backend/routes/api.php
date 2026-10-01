<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\StorefrontController;
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

    // Storefront publik
    Route::get('shops', [StorefrontController::class, 'index']);
    Route::get('shops/{slug}', [StorefrontController::class, 'show']);
    Route::get('shops/{slug}/products/{productSlug}', [StorefrontController::class, 'product']);

    // Utilitas publik
    Route::get('shipping/cities', [ShippingController::class, 'cities']);
    Route::post('shipping/cost', [ShippingController::class, 'cost']);
    Route::get('shipping/track', [ShippingController::class, 'track']);

    // Checkout & status order publik
    Route::post('checkout', [CheckoutController::class, 'store']);
    Route::get('orders/track/{orderNo}', [CheckoutController::class, 'track']);
});

// ---------- Webhook (signature-verified, tanpa auth) ----------
Route::prefix('v1/webhooks')->middleware('throttle:api-public')->group(function () {
    Route::post('midtrans', [WebhookController::class, 'midtrans']);
});

// ---------- Merchant (butuh login + rate limit longgar) ----------
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api-auth'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/publish', [ProductController::class, 'publish']);

    Route::get('channels', [ChannelController::class, 'index']);
    Route::post('channels', [ChannelController::class, 'store']);
    Route::put('channels/{channel}', [ChannelController::class, 'update']);
    Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);
    Route::post('channels/{channel}/verify', [ChannelController::class, 'verify']);

    Route::post('ai/caption', [AiController::class, 'caption']);
    Route::post('ai/preview', [AiController::class, 'preview']);

    Route::get('orders', [CheckoutController::class, 'index']);
    Route::get('orders/{order}', [CheckoutController::class, 'show']);
    Route::put('orders/{order}/ship', [CheckoutController::class, 'ship']);
});
