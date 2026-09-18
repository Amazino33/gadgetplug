<?php

use App\Http\Controllers\AffiliateClickController;
use App\Http\Controllers\Payment\PaystackCallbackController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Storefront\FeedActionController;
use App\Http\Controllers\Storefront\FeedController;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.product-catalog')
    ->middleware(\App\Http\Middleware\RedirectVendorToDashboard::class)
    ->name('home');
Volt::route('/track', 'pages.track-order')->name('track-order');
Route::view('/privacy-policy', 'pages.privacy-policy')->name('privacy-policy');
Volt::route('/product/{product:slug}', 'pages.product-detail')->name('product.show');
// A store's own page — where the feed's store line lands, so a customer who
// likes one product can see the rest of what that shop sells.
Volt::route('/store/{vendor:slug}', 'pages.vendor-store')->name('store.show');
Volt::route('/cart', 'pages.cart')->name('cart');

// The social feed's data, called on every scroll. Kept as plain JSON routes
// rather than Livewire methods so the payload is exactly what a post needs and
// nothing more — this runs on mobile data.
Route::get('/feed/posts',      [FeedController::class, 'index'])->name('feed.posts');
Route::get('/feed/categories', [FeedController::class, 'categories'])->name('feed.categories');

// What a tap on a post does. Every rule is enforced in the controller as well
// as in the interface — the UI gate is a courtesy, this is the control.
//
// Bound on :id explicitly. Product::getRouteKeyName() is 'slug' for the sake of
// the public product URL, but a feed post is addressed by id — that is what the
// JSON payload carries and what the client posts. Without the field here the
// binding looked for a slug equal to "123", found nothing, and answered 404 to
// every tap: visibly on Buy Now, which navigates, and silently on the other
// three, whose fetch failure was swallowed and rolled the button back.
//
// route() honours the binding field when generating these too, so the tests
// exercise the same URL the browser does rather than a slug URL nothing calls.
Route::post('/feed/{product:id}/like',  [FeedActionController::class, 'like'])->name('feed.like');
Route::post('/feed/{product:id}/save',  [FeedActionController::class, 'save'])->name('feed.save');
Route::post('/feed/{product:id}/share', [FeedActionController::class, 'share'])->name('feed.share');
Route::post('/feed/{product:id}/buy',   [FeedActionController::class, 'buyNow'])->name('feed.buy');
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
    // A blocked account loses the till as well as the panel. Checked at the
    // page rather than only in the API so the terminal never boots and asks
    // for a PIN it is going to refuse anyway.
    if ($vendor->isDashboardBlocked() && ! auth()->user()?->isSuperAdmin()) {
        return response()->view('vendor.blocked', ['vendor' => $vendor], 403);
    }

    return view('pos.index', [
        'vendorId'   => $vendor->id,
        'vendorSlug' => $vendor->slug,
        'vendorName' => $vendor->name,
        'panelUrl'   => url("/plug/{$vendor->slug}"),
    ]);
})->name('pos.vendor');

// The customer's own copy, opened by the QR on the paper. Public by necessity --
// a customer walking out has no account -- so the random token is the secret, and
// the page never renders the customer's name or phone. Short path keeps the
// printed QR coarse enough to scan off thermal paper.
Route::get('/receipt/{token}', [App\Http\Controllers\PublicReceiptController::class, 'show'])
    ->name('receipt.public');
Route::get('/receipt/{token}/pdf', [App\Http\Controllers\PublicReceiptController::class, 'pdf'])
    ->name('receipt.public.pdf');
Route::post('/receipt/{token}/loyalty', [App\Http\Controllers\PublicReceiptController::class, 'claimLoyalty'])
    ->name('receipt.public.loyalty');

// Where a scanned cash-handover code lands. Authenticated, because the whole
// point is that a named person with the authority to receive cash at that
// branch is the one answering — an anonymous visitor confirming a handover
// would defeat the entire arrangement.
Route::middleware('auth')->group(function () {
    Route::get('/cash/handoff/{token}', [App\Http\Controllers\CashHandoffController::class, 'show'])
        ->name('cash.handoff');
    Route::post('/cash/handoff/{token}/confirm', [App\Http\Controllers\CashHandoffController::class, 'confirm'])
        ->name('cash.handoff.confirm');
    Route::post('/cash/handoff/{token}/dispute', [App\Http\Controllers\CashHandoffController::class, 'dispute'])
        ->name('cash.handoff.dispute');

    // The settlement statement, on screen and as the printable copy. Both read
    // the same frozen payload, so the two can never disagree.
    Route::get('/settlement/{statement}', [App\Http\Controllers\SettlementStatementController::class, 'show'])
        ->name('settlement.show');
    Route::get('/settlement/{statement}/pdf', [App\Http\Controllers\SettlementStatementController::class, 'pdf'])
        ->name('settlement.pdf');
});

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
    Route::post('/supplier/api', [App\Http\Controllers\ProcurementWizardController::class, 'storeSupplierApi'])->name('storeSupplierApi');
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


// Guided tours: the browser telling us this person has now been shown one, so
// the auto-offer never asks the same person twice.
Route::middleware(['auth'])
    ->post('/tours/progress', [App\Http\Controllers\TourProgressController::class, 'store'])
    ->name('tours.progress');

require __DIR__.'/settings.php';
