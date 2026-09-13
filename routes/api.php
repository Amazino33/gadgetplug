<?php

use App\Http\Controllers\Pos\PosAuthController;
use App\Http\Controllers\Pos\PosProductController;
use App\Http\Controllers\Pos\PosCustomerController;
use App\Http\Controllers\Pos\PosSaleController;
use App\Http\Controllers\Pos\PosReceiptController;
use App\Http\Controllers\Pos\PosSessionController;
use App\Http\Controllers\Pos\PosCashController;
use App\Http\Controllers\Pos\PosExpenseController;
use App\Http\Controllers\Pos\PosPickingController;
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

        // The cashier's trading day. One session per cashier per branch per
        // day: opened with a counted float, closed with a counted drawer and a
        // Moniepoint reading. The session IS the cash-up — there is no second
        // record and no second set of endpoints.
        //
        // Rectifying a difference is deliberately absent: only a manager may
        // explain a gap, and they do it from the panel.
        Route::post('sessions/open',               [PosSessionController::class, 'open']);
        Route::post('sessions/{session}/close',    [PosSessionController::class, 'close']);
        Route::get('sessions/{session}/z-report',  [PosSessionController::class, 'zReport']);
        Route::get('sessions/active',              [PosSessionController::class, 'active']);
        Route::get('sessions/history',             [PosSessionController::class, 'history']);

        // Suspended sales
        Route::get('suspended',                          [PosSessionController::class, 'listSuspended']);
        Route::post('suspended',                         [PosSessionController::class, 'suspend']);
        Route::post('suspended/{suspendedSale}/resume',  [PosSessionController::class, 'resume']);
        Route::delete('suspended/{suspendedSale}',       [PosSessionController::class, 'clearSuspended']);

        // Offline sync — bulk submit queued transactions
        // Vendor pickings: what is still out at this till's branch, and the
        // money traders bring back for it. The payment endpoint doubles as the
        // one the offline queue replays.
        Route::get('pickings',          [PosPickingController::class, 'index']);
        Route::post('pickings/payment', [PosPickingController::class, 'pay']);
        Route::post('pickings/release', [PosPickingController::class, 'release']);
        Route::post('pickings/return',  [PosPickingController::class, 'returnItems']);

        // Money paid out of the drawer, recorded when it happens rather than
        // reconstructed at the end of the day. Lands on the same expenses
        // dashboard the panel writes to, and lowers what the drawer should hold.
        Route::get('expenses',  [PosExpenseController::class, 'index']);
        Route::post('expenses', [PosExpenseController::class, 'store']);

        // Handing the day's takings over. Online only — see the controller.
        Route::get('cash',        [PosCashController::class, 'index']);
        Route::post('cash/submit', [PosCashController::class, 'submit']);


        Route::post('sync', [PosSyncController::class, 'sync']);
    });
});
