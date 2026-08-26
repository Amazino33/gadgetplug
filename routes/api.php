<?php

use App\Http\Controllers\Pos\PosAuthController;
use App\Http\Controllers\Pos\PosProductController;
use App\Http\Controllers\Pos\PosCustomerController;
use App\Http\Controllers\Pos\PosSaleController;
use App\Http\Controllers\Pos\PosReceiptController;
use App\Http\Controllers\Pos\PosSessionController;
use App\Http\Controllers\Pos\PosSyncController;
use App\Http\Middleware\EnsurePosVendorAccess;
use App\Http\Middleware\NoStoreApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->middleware(NoStoreApiResponse::class)->group(function () {

    // PIN auth — no token required
    Route::post('auth/login',  [PosAuthController::class, 'login']);
    Route::post('auth/logout', [PosAuthController::class, 'logout'])->middleware('auth:sanctum');

    // Authenticated AND authorised for the vendor the till claims to be. The
    // second half is not optional: a valid token says who you are, not whose
    // store you may ring up sales in.
    Route::middleware(['auth:sanctum', EnsurePosVendorAccess::class])->group(function () {

        // Products — initial load + barcode/name search
        Route::get('products',        [PosProductController::class, 'index']);
        Route::get('products/search', [PosProductController::class, 'search']);

        // Customers
        Route::get('customers',       [PosCustomerController::class, 'index']);
        Route::post('customers',      [PosCustomerController::class, 'store']);

        // Route-model bound, so EnsurePosVendorAccess checks the customer's own
        // vendor rather than trusting a vendor_id in the query string.
        Route::get('customers/{customer}/outstanding', [PosCustomerController::class, 'outstanding']);

        // Sales
        Route::post('sales',                       [PosSaleController::class, 'store']);
        Route::get('sales/{sale}/receipt',         [PosReceiptController::class, 'show']);
        Route::post('sales/{sale}/void',           [PosSaleController::class, 'void']);
        Route::post('sales/{sale}/return',         [PosSaleController::class, 'processReturn']);
        Route::get('sales/{reference}/by-ref',     [PosSaleController::class, 'findByReference']);
        Route::get('sales/my-history',              [PosSaleController::class, 'myHistory']);

        // Discounts — manager PIN approval
        Route::post('discounts/approve', [PosSaleController::class, 'approveDiscount']);

        // Sessions
        Route::post('sessions/open',               [PosSessionController::class, 'open']);
        Route::post('sessions/{session}/close',    [PosSessionController::class, 'close']);
        Route::get('sessions/{session}/z-report',  [PosSessionController::class, 'zReport']);
        Route::get('sessions/active',              [PosSessionController::class, 'active']);

        // Suspended sales
        Route::get('suspended',                          [PosSessionController::class, 'listSuspended']);
        Route::post('suspended',                         [PosSessionController::class, 'suspend']);
        Route::post('suspended/{suspendedSale}/resume',  [PosSessionController::class, 'resume']);
        Route::delete('suspended/{suspendedSale}',       [PosSessionController::class, 'clearSuspended']);

        // Offline sync — bulk submit queued transactions
        Route::post('sync', [PosSyncController::class, 'sync']);
    });
});
