<?php

use App\Http\Controllers\Payment\PaystackCallbackController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'pages.product-catalog')->name('home');
Volt::route('/product/{product:slug}', 'pages.product-detail')->name('product.show');
Volt::route('/cart', 'pages.cart')->name('cart');
Volt::route('/checkout', 'checkout')->name('checkout');

Route::get('/payment/callback', PaystackCallbackController::class)->name('payment.callback');


Route::redirect('/dashboard', '/account')->name('dashboard');

Route::middleware(['auth'])->prefix('account')->group(function () {
    Volt::route('/',               'pages.account.profile')->name('account.profile');
    Volt::route('/orders',         'pages.account.orders')->name('account.orders');
    Volt::route('/wishlist',       'pages.account.wishlist')->name('account.wishlist');
    Volt::route('/become-a-plug',  'pages.account.vendor-apply')->name('account.vendor-apply');
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

require __DIR__.'/settings.php';
