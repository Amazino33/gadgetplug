<?php

use App\Http\Controllers\Pos\PosAuthController;
use App\Http\Controllers\Pos\PosProductController;
use App\Http\Controllers\Pos\PosCustomerController;
use App\Http\Controllers\Pos\PosSaleController;
use App\Http\Controllers\Pos\PosSessionController;
use App\Http\Controllers\Pos\PosSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->group(function () {

    // PIN auth — no token required
    Route::post('auth/login',  [PosAuthController::class, 'login']);
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->middleware('auth:sanctum');

    Route::middleware('auth:sanctum')->group(function () {

        // Products — initial load + barcode/name search
        Route::get('products',        [PosProductController::class, 'index']);
        Route::get('products/search', [PosProductController::class, 'search']);

        // Customers
        Route::get('customers',       [PosCustomerController::class, 'index']);
        Route::post('customers',      [PosCustomerController::class, 'store']);

        // Sales
        Route::post('sales',                       [PosSaleController::class, 'store']);
        Route::post('sales/{sale}/void',           [PosSaleController::class, 'void']);
        Route::post('sales/{sale}/return',         [PosSaleController::class, 'processReturn']);
        Route::get('sales/{reference}/by-ref',     [PosSaleController::class, 'findByReference']);

        // Discounts — manager PIN approval
        Route::post('discounts/approve', [PosSaleController::class, 'approveDiscount']);

        // Sessions
        Route::post('sessions/open',               [PosSessionController::class, 'open']);
        Route::post('sessions/{session}/close',    [PosSessionController::class, 'close']);
        Route::get('sessions/{session}/z-report',  [PosSessionController::class, 'zReport']);
        Route::get('sessions/active',              [PosSessionController::class, 'active']);

        // Suspended sales
        Route::get('suspended',                    [PosSessionController::class, 'listSuspended']);
        Route::post('suspended',                   [PosSessionController::class, 'suspend']);
        Route::post('suspended/{slot}/resume',     [PosSessionController::class, 'resume']);
        Route::delete('suspended/{slot}',          [PosSessionController::class, 'clearSlot']);

        // Offline sync — bulk submit queued transactions
        Route::post('sync', [PosSyncController::class, 'sync']);
    });
});
