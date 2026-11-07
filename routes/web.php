<?php

use App\Http\Controllers\AffiliateClickController;
use App\Http\Controllers\CashHandoffController;
use App\Http\Controllers\DispatchPageController;
use App\Http\Controllers\Payment\PaystackCallbackController;
use App\Http\Controllers\Payment\PaystackWebhookController;
use App\Http\Controllers\Pos\PosReceiptController;
use App\Http\Controllers\ProcurementWizardController;
use App\Http\Controllers\PublicReceiptController;
use App\Http\Controllers\SettlementStatementController;
use App\Http\Controllers\StoreAccountCloseController;
use App\Http\Controllers\Storefront\FeedActionController;
use App\Http\Controllers\Storefront\FeedController;
use App\Http\Controllers\Storefront\LegalPolicyController;
use App\Http\Controllers\Storefront\SitemapController;
use App\Http\Controllers\TourProgressController;
use App\Http\Controllers\VendorInviteController;
use App\Http\Middleware\RedirectVendorToDashboard;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.product-catalog')
    ->middleware(RedirectVendorToDashboard::class)
    ->name('home');
Route::redirect('/vendor', '/plug');
Volt::route('/track', 'pages.track-order')->name('track-order');
Route::get('/privacy-policy', [LegalPolicyController::class, 'privacy'])->name('privacy-policy');
Route::get('/terms', [LegalPolicyController::class, 'terms'])->name('terms');
Route::get('/terms-of-service', [LegalPolicyController::class, 'terms'])->name('terms.service');
Route::get('/affiliate-terms', [LegalPolicyController::class, 'affiliateTerms'])->name('affiliate-terms');
Route::get('/refund-policy', [LegalPolicyController::class, 'refundPolicy'])->name('refund-policy');
Route::get('/legal/{slug}', [LegalPolicyController::class, 'show'])->name('legal.show');
Volt::route('/product/{product:slug}', 'pages.product-detail')->name('product.show');
// A store's own page — where the feed's store line lands, so a customer who
// likes one product can see the rest of what that shop sells.
// Every store that sells online, verified first — where "Browse Verified
// Plugs" on the home hero lands.
Volt::route('/stores', 'pages.stores')->name('stores.index');
Volt::route('/store/{vendor:slug}', 'pages.vendor-store')->name('store.show');
Volt::route('/cart', 'pages.cart')->name('cart');

// Every customer-visible page, on the storefront host. robots.txt points here.
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// The social feed's data, called on every scroll. Kept as plain JSON routes
// rather than Livewire methods so the payload is exactly what a post needs and
// nothing more — this runs on mobile data.
Route::get('/feed/posts', [FeedController::class, 'index'])->name('feed.posts');
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
Route::post('/feed/{product:id}/like', [FeedActionController::class, 'like'])->name('feed.like');
Route::post('/feed/{product:id}/save', [FeedActionController::class, 'save'])->name('feed.save');
Route::post('/feed/{product:id}/share', [FeedActionController::class, 'share'])->name('feed.share');
Route::post('/feed/{product:id}/buy', [FeedActionController::class, 'buyNow'])->name('feed.buy');
Volt::route('/checkout', 'checkout')->name('checkout');

Route::get('/payment/callback', PaystackCallbackController::class)->name('payment.callback');

// Server-to-server confirmation from Paystack, for the customer who pays and
// closes the tab before the redirect above. Excluded from CSRF in
// bootstrap/app.php; authenticated by the x-paystack-signature header instead.
Route::post('/paystack/webhook', PaystackWebhookController::class)->name('paystack.webhook');

// The rider's page for one platform-dispatched order. No login: reached only
// by the signed, 48-hour link GadgetPlug sends the logistics company, and
// tied to the current assignment (v). Marking delivered needs the customer's
// code; the POST goes back to the same signed address and is rate limited.
Route::middleware('signed')->whereNumber(['order', 'v'])->group(function () {
    Route::get('/dispatch/{order}/{v}', [DispatchPageController::class, 'show'])->name('dispatch.show');
    Route::post('/dispatch/{order}/{v}', [DispatchPageController::class, 'deliver'])
        ->middleware('throttle:10,1')
        ->name('dispatch.deliver');
});

Route::get('/r/{code}', [AffiliateClickController::class, 'redirect'])->name('affiliate.click');

Route::get('/affiliate/join', function () {
    if (auth()->check()) {
        return redirect()->route('account.affiliate-apply');
    }

    session()->put('url.intended', route('account.affiliate-apply', absolute: false));

    return view('pages.affiliate-join');
})->name('affiliate.join');


// The free plan: a small business signs itself up, is reviewed by the
// platform admin, and gets a shop link. Rate limits are named limiters
// (AppServiceProvider) so the link check and the submit keep separate counts.
Route::get('/become-a-vendor', [App\Http\Controllers\FreeShopSignupController::class, 'create'])
    ->name('free-shop.create');
Route::post('/become-a-vendor', [App\Http\Controllers\FreeShopSignupController::class, 'store'])
    ->middleware('throttle:free-shop-signup')
    ->name('free-shop.store');
Route::get('/become-a-vendor/check-link', [App\Http\Controllers\FreeShopSignupController::class, 'checkSlug'])
    ->middleware('throttle:free-shop-slug-check')
    ->name('free-shop.check-slug');

Route::middleware('auth')->group(function () {
    Route::get('/my-shop/application', [App\Http\Controllers\FreeShopApplicationController::class, 'edit'])
        ->name('free-shop.application.edit');
    Route::put('/my-shop/application', [App\Http\Controllers\FreeShopApplicationController::class, 'update'])
        ->middleware('throttle:free-shop-signup')
        ->name('free-shop.application.update');

    // Platform admin only (checked in the controller). Bound by id: the
    // reviewer's link must not change if the shop's link name does.
    Route::get('/platform/free-shops/{vendor:id}/verification-photo', App\Http\Controllers\FreeShopVerificationPhotoController::class)
        ->name('free-shop.verification-photo');
});

Route::redirect('/dashboard', '/account')->name('dashboard');

Route::middleware(['auth'])->prefix('account')->group(function () {
    Volt::route('/', 'pages.account.profile')->name('account.profile');
    Volt::route('/orders', 'pages.account.orders')->name('account.orders');
    Volt::route('/wishlist', 'pages.account.wishlist')->name('account.wishlist');
    Volt::route('/become-a-plug', 'pages.account.vendor-apply')->name('account.vendor-apply');
    Volt::route('/become-an-affiliate', 'pages.account.affiliate-apply')->name('account.affiliate-apply');
    Volt::route('/affiliate', 'pages.account.affiliate')->name('account.affiliate');
});

Route::get('/nuke-cache', function () {
    Artisan::call('optimize:clear');
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    return 'Web cache and OPcache destroyed!';
});

Route::get('/invite/{token}', [VendorInviteController::class, 'accept'])
    ->name('vendor.invite.accept');

Route::post('/invite/{token}', [VendorInviteController::class, 'store'])
    ->name('vendor.invite.store');

// The till (Sales Point) — vendor-scoped entry point from the Filament panel.
//
// It lived at /pos until October 2026. In Nigeria "POS" is the card machine, so
// the till was renamed Sales Point and moved here; /pos still redirects (below),
// so installed till icons and bookmarks keep working. Route names keep "pos":
// they are code, not words anyone reads.
Route::get('/sales-point/{vendor:slug}', function (Vendor $vendor) {
    // A blocked account loses the till as well as the panel. Checked at the
    // page rather than only in the API so the terminal never boots and asks
    // for a PIN it is going to refuse anyway.
    if ($vendor->isDashboardBlocked() && ! auth()->user()?->isSuperAdmin()) {
        return response()->view('vendor.blocked', ['vendor' => $vendor], 403);
    }

    // A free (self-signup) shop has no till; back to its own panel.
    if ($vendor->isFreePlan() && ! auth()->user()?->isSuperAdmin()) {
        return redirect()->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
    }

    return view('pos.index', [
        'vendorId' => $vendor->id,
        'vendorSlug' => $vendor->slug,
        'vendorName' => $vendor->name,
        'panelUrl' => url("/plug/{$vendor->slug}"),
    ]);
})->name('pos.vendor');

// The customer's own copy, opened by the QR on the paper. Public by necessity --
// a customer walking out has no account -- so the random token is the secret, and
// the page never renders the customer's name or phone. Short path keeps the
// printed QR coarse enough to scan off thermal paper.
Route::get('/receipt/{token}', [PublicReceiptController::class, 'show'])
    ->name('receipt.public');
Route::get('/receipt/{token}/pdf', [PublicReceiptController::class, 'pdf'])
    ->name('receipt.public.pdf');
Route::post('/receipt/{token}/loyalty', [PublicReceiptController::class, 'claimLoyalty'])
    ->name('receipt.public.loyalty');

// Where a scanned cash-handover code lands. Authenticated, because the whole
// point is that a named person with the authority to receive cash at that
// branch is the one answering — an anonymous visitor confirming a handover
// would defeat the entire arrangement.
Route::middleware('auth')->group(function () {
    Route::get('/cash/handoff/{token}', [CashHandoffController::class, 'show'])
        ->name('cash.handoff');
    Route::post('/cash/handoff/{token}/confirm', [CashHandoffController::class, 'confirm'])
        ->name('cash.handoff.confirm');
    Route::post('/cash/handoff/{token}/dispute', [CashHandoffController::class, 'dispute'])
        ->name('cash.handoff.dispute');

    // The settlement statement, on screen and as the printable copy. Both read
    // the same frozen payload, so the two can never disagree.
    Route::get('/settlement/{statement}', [SettlementStatementController::class, 'show'])
        ->name('settlement.show');
    Route::get('/settlement/{statement}/pdf', [SettlementStatementController::class, 'pdf'])
        ->name('settlement.pdf');

    // The account close. Same arrangement as the statement above, over the
    // close's own frozen payload rather than the statement's.
    Route::get('/account-close/{close}', [StoreAccountCloseController::class, 'show'])
        ->name('account-close.show');
    Route::get('/account-close/{close}/pdf', [StoreAccountCloseController::class, 'pdf'])
        ->name('account-close.pdf');
});

// A sale rendered as an 80mm receipt document, printed from its own page rather
// than out of the POS modal. Session-authenticated and vendor-scoped: it names
// the cashier and customer, so it is not the customer-facing copy.
Route::get('/sales-point/receipt/{sale}', [PosReceiptController::class, 'show'])
    ->middleware('auth')
    ->name('pos.receipt');

// Fallback — bare /sales-point with no vendor context
Route::get('/sales-point', fn () => view('pos.index', [
    'vendorId' => null,
    'vendorSlug' => null,
    'vendorName' => null,
    'panelUrl' => null,
]))->name('pos');

// The till's old address. Every path under it, query string and all, goes to
// the same place under /sales-point, so a till icon installed before the move
// still opens the till.
Route::get('/pos/{path?}', function (Request $request, ?string $path = null) {
    $query = $request->getQueryString();

    return redirect('/sales-point'.($path ? '/'.$path : '').($query ? '?'.$query : ''), 301);
})->where('path', '.*');

// Procurement Wizard
Route::middleware(['auth'])->prefix('procurement')->name('procurement.')->group(function () {
    Route::get('/create', [ProcurementWizardController::class, 'create'])->name('create');
    Route::post('/supplier/api', [ProcurementWizardController::class, 'storeSupplierApi'])->name('storeSupplierApi');
    Route::post('/supplier', [ProcurementWizardController::class, 'storeSupplier'])->name('storeSupplier');
    Route::get('/items', [ProcurementWizardController::class, 'items'])->name('items');
    Route::post('/items', [ProcurementWizardController::class, 'storeItems'])->name('storeItems');
    Route::get('/logistics', [ProcurementWizardController::class, 'logistics'])->name('logistics');
    Route::post('/logistics', [ProcurementWizardController::class, 'storeLogistics'])->name('storeLogistics');
    Route::get('/financials', [ProcurementWizardController::class, 'financials'])->name('financials');
    Route::post('/financials', [ProcurementWizardController::class, 'storeFinancials'])->name('storeFinancials');
    Route::get('/confirm', [ProcurementWizardController::class, 'confirm'])->name('confirm');
    Route::post('/submit', [ProcurementWizardController::class, 'submit'])->name('submit');
});

// Guided tours: the browser telling us this person has now been shown one, so
// the auto-offer never asks the same person twice.
Route::middleware(['auth'])
    ->post('/tours/progress', [TourProgressController::class, 'store'])
    ->name('tours.progress');

require __DIR__.'/settings.php';
