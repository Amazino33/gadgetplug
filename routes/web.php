<?php

use App\Http\Controllers\AffiliateClickController;
use App\Http\Controllers\Payment\PaystackCallbackController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.product-catalog')->name('home');
Volt::route('/track', 'pages.track-order')->name('track-order');
Route::view('/privacy-policy', 'pages.privacy-policy')->name('privacy-policy');
Volt::route('/product/{product:slug}', 'pages.product-detail')->name('product.show');
Volt::route('/cart', 'pages.cart')->name('cart');
Volt::route('/checkout', 'checkout')->name('checkout');

Route::get('/payment/callback', PaystackCallbackController::class)->name('payment.callback');

Route::get('/r/{code}', [AffiliateClickController::class, 'redirect'])->name('affiliate.click');


Route::redirect('/dashboard', '/account')->name('dashboard');

Route::middleware(['auth'])->prefix('account')->group(function () {
    Volt::route('/',               'pages.account.profile')->name('account.profile');
    Volt::route('/orders',         'pages.account.orders')->name('account.orders');
    Volt::route('/wishlist',       'pages.account.wishlist')->name('account.wishlist');
    Volt::route('/become-a-plug',       'pages.account.vendor-apply')->name('account.vendor-apply');
    Volt::route('/become-an-affiliate', 'pages.account.affiliate-apply')->name('account.affiliate-apply');
    Volt::route('/affiliate',           'pages.account.affiliate')->name('account.affiliate');
});

Route::get('/nuke-cache', function () {
    Artisan::call('optimize:clear');
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    return 'Web cache and OPcache destroyed!';
});

Route::get('/invite/{token}', [App\Http\Controllers\VendorInviteController::class, 'accept'])
    ->name('vendor.invite.accept');

Route::post('/invite/{token}', [App\Http\Controllers\VendorInviteController::class, 'store'])
    ->name('vendor.invite.store');

// POS SPA — vendor-scoped entry point from Filament panel
Route::get('/pos/{vendor:slug}', function (\App\Models\Vendor $vendor) {
    return view('pos.index', [
        'vendorId'   => $vendor->id,
        'vendorSlug' => $vendor->slug,
        'vendorName' => $vendor->name,
        'panelUrl'   => url("/plug/{$vendor->slug}"),
    ]);
})->name('pos.vendor');

// A sale rendered as an 80mm receipt document, printed from its own page rather
// than out of the POS modal. Session-authenticated and vendor-scoped: it names
// the cashier and customer, so it is not the customer-facing copy.
Route::get('/pos/receipt/{sale}', [App\Http\Controllers\Pos\PosReceiptController::class, 'show'])
    ->middleware('auth')
    ->name('pos.receipt');

// Fallback — bare /pos with no vendor context
Route::get('/pos', fn () => view('pos.index', [
    'vendorId'   => null,
    'vendorSlug' => null,
    'vendorName' => null,
    'panelUrl'   => null,
]))->name('pos');

// Procurement Wizard
Route::middleware(['auth'])->prefix('procurement')->name('procurement.')->group(function () {
    Route::get('/create',     [App\Http\Controllers\ProcurementWizardController::class, 'create'])->name('create');
    Route::post('/supplier',  [App\Http\Controllers\ProcurementWizardController::class, 'storeSupplier'])->name('storeSupplier');
    Route::get('/items',      [App\Http\Controllers\ProcurementWizardController::class, 'items'])->name('items');
    Route::post('/items',     [App\Http\Controllers\ProcurementWizardController::class, 'storeItems'])->name('storeItems');
    Route::get('/logistics',  [App\Http\Controllers\ProcurementWizardController::class, 'logistics'])->name('logistics');
    Route::post('/logistics', [App\Http\Controllers\ProcurementWizardController::class, 'storeLogistics'])->name('storeLogistics');
    Route::get('/financials', [App\Http\Controllers\ProcurementWizardController::class, 'financials'])->name('financials');
    Route::post('/financials',[App\Http\Controllers\ProcurementWizardController::class, 'storeFinancials'])->name('storeFinancials');
    Route::get('/confirm',    [App\Http\Controllers\ProcurementWizardController::class, 'confirm'])->name('confirm');
    Route::post('/submit',    [App\Http\Controllers\ProcurementWizardController::class, 'submit'])->name('submit');
});


require __DIR__.'/settings.php';
