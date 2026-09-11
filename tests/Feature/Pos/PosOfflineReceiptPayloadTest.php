<?php

use App\Models\Store;
use App\Models\VendorReceiptSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A sale rung with no signal has no server id, so there is no receipt document
 * to fetch — the till has to build one itself. Everything it needs for that is
 * sent once, at login, and kept on the device.
 *
 * Before this, the browser knew the vendor's name and nothing else: no address,
 * no branch, no layout settings. So it printed its own on-screen modal instead,
 * and the customer got a visibly worse receipt purely because the connection
 * was down.
 */
function loginAsTill(array $ctx, string $pin = '1234')
{
    $ctx['owner']->forceFill(['pos_pin' => Hash::make($pin)])->save();

    return test()->postJson('/api/pos/auth/login', [
        'vendor_id' => $ctx['vendor']->id,
        'pin'       => $pin,
    ]);
}

it('sends the receipt layout the till needs to print without the server', function () {
    $ctx = debtTenderContext();

    VendorReceiptSetting::create([
        'vendor_id'        => $ctx['vendor']->id,
        'header_name'      => 'CREDIT STORE LTD',
        'header_tagline'   => 'Your gadget people',
        'header_address'   => '3 Head Office Way, Lagos',
        'header_phone'     => '08030000000',
        'header_alignment' => 'center',
        'footer_text'      => 'No refunds after 7 days.',
        'feed_lines'       => 3,
    ]);

    $receipt = loginAsTill($ctx)->assertOk()->json('vendor.receipt');

    expect($receipt['header_name'])->toBe('CREDIT STORE LTD')
        ->and($receipt['header_tagline'])->toBe('Your gadget people')
        ->and($receipt['header_address'])->toBe('3 Head Office Way, Lagos')
        ->and($receipt['header_phone'])->toBe('08030000000')
        ->and($receipt['footer_text'])->toBe('No refunds after 7 days.')
        ->and($receipt['feed_lines'])->toBe(3);
});

it('falls back to the column defaults for a store that never opened the settings page', function () {
    $ctx = debtTenderContext();

    $receipt = loginAsTill($ctx)->assertOk()->json('vendor.receipt');

    // The most common path of all, so it must be the well-arranged one: a brand
    // new store still gets a number, a date and a cashier on its paper.
    expect($receipt['show_receipt_number'])->toBeTrue()
        ->and($receipt['show_datetime'])->toBeTrue()
        ->and($receipt['show_cashier'])->toBeTrue()
        ->and($receipt['show_item_unit_price'])->toBeTrue()
        ->and($receipt['header_name'])->toBe('Credit Store')
        ->and($receipt['feed_lines'])->toBe(2);
});

it('never sends a QR setting, because an offline sale has no online copy to link to', function () {
    $ctx = debtTenderContext();

    expect(loginAsTill($ctx)->json('vendor.receipt'))->not->toHaveKey('show_qr');
});

it('names the branch only when the vendor has more than one store', function () {
    $ctx = debtTenderContext();

    // One store: the branch line tells the customer nothing they can act on.
    expect(loginAsTill($ctx)->json('vendor.store.show'))->toBeFalse();

    $second = Store::create([
        'vendor_id' => $ctx['vendor']->id,
        'name'      => 'Ikeja Branch',
        'address'   => '12 Allen Avenue',
        'phone'     => '08031234567',
    ]);
    $ctx['owner']->stores()->sync([$second->id]);

    $store = loginAsTill($ctx)->json('vendor.store');

    expect($store['show'])->toBeTrue()
        ->and($store['name'])->toBe('Ikeja Branch')
        ->and($store['address'])->toBe('12 Allen Avenue')
        ->and($store['phone'])->toBe('08031234567');
});

it('resolves the branch the same way a sale does — from the cashier assignment', function () {
    $ctx = debtTenderContext();

    $assigned = Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Surulere Branch', 'address' => '9 Bode Thomas',
    ]);
    Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Yaba Branch']);

    $ctx['owner']->stores()->sync([$assigned->id]);

    expect(loginAsTill($ctx)->json('vendor.store.name'))->toBe('Surulere Branch');
});

it('still sends the VAT settings the till computes with', function () {
    $ctx = debtTenderContext();
    $ctx['vendor']->forceFill(['pos_vat_enabled' => false, 'pos_vat_rate' => 5])->save();

    $vendor = loginAsTill($ctx)->assertOk()->json('vendor');

    // The receipt prints the rate the sale was computed with. A hardcoded 7.5%
    // was a wrong tax figure on the paper of every store on another rate.
    expect($vendor['vat_enabled'])->toBeFalse()
        ->and((float) $vendor['vat_rate'])->toBe(5.0)
        ->and($vendor['vendor_name'])->toBe('Credit Store');
});

it('sends the layout again when a session is opened, so a change need not wait for a logout', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    VendorReceiptSetting::create([
        'vendor_id'   => $ctx['vendor']->id,
        'header_name' => 'MID-SHIFT REBRAND',
        'footer_text' => 'Now open Sundays.',
    ]);

    $opened = test()->postJson('/api/pos/sessions/open', [
        'vendor_id'     => $ctx['vendor']->id,
        'opening_float' => 0,
    ])->assertCreated();

    expect($opened->json('vendor_settings.receipt.header_name'))->toBe('MID-SHIFT REBRAND')
        ->and($opened->json('vendor_settings.receipt.footer_text'))->toBe('Now open Sundays.');
});

it('still returns the session itself, which the till reads for the shift', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $opened = test()->postJson('/api/pos/sessions/open', [
        'vendor_id'     => $ctx['vendor']->id,
        'opening_float' => 5000,
    ])->assertCreated();

    expect($opened->json('id'))->not->toBeNull()
        ->and($opened->json('status'))->toBe('open')
        ->and((float) $opened->json('opening_float'))->toBe(5000.0);
});
