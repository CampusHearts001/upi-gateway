<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\OrderApiController;
use Illuminate\Support\Facades\Route;

// API key and per-IP throttling is handled inside AuthenticateApiKey.
Route::prefix('v1')->group(function () {
    Route::middleware(['api.key'])->group(function () {
        Route::get('accounts', [AccountApiController::class, 'index']);
        Route::get('orders/{orderNo}', [OrderApiController::class, 'show']);
    });

    // Flash-sale order creation has a separate per-merchant key bucket
    // (1000/min by default), while retaining the shared per-IP abuse limit.
    Route::post('orders', [OrderApiController::class, 'store'])->middleware('api.key:order_create');
});
