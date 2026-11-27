<?php

use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\StockController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'timestamp' => now()->toISOString(),
    ]));

    // Auth (Opsional — Sanctum Bearer Token)
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Dashboard Ringkasan Inventaris
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Master Kategori
    Route::apiResource('categories', CategoryController::class);

    // Katalog & Pencarian Produk
    Route::get('/products/lookup', [ProductController::class, 'lookup']);
    Route::apiResource('products', ProductController::class);

    // Pergerakan & Riwayat Stok (IN / OUT / OPNAME)
    Route::post('/products/{product}/stock', [StockController::class, 'adjust']);
    Route::get('/products/{product}/stock-logs', [StockController::class, 'productLogs']);
    Route::get('/stock-logs', [StockController::class, 'index']);

    // Integrasi Layanan AI (FastAPI ML & Gen AI)
    Route::prefix('ai')->group(function (): void {
        Route::post('/classify', [AiController::class, 'classify']);
        Route::post('/generate-description', [AiController::class, 'generateDescription']);
        Route::get('/logs', [AiController::class, 'logs']);
    });
});
