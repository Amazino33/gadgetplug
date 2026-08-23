<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorReceiptSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptVendor(): array
{
    $owner  = User::factory()->create(['name' => 'Owner Person']);
    $vendor = Vendor::create([
        'user_id'         => $owner->id,
        'name'            => 'Zelink Tech',
        'slug'            => 'zelink-tech',
        'pos_vat_enabled' => true,
        'pos_vat_rate'    => 7.5,
    ]);

    $cashier = User::factory()->create(['name' => 'Grace Cashier']);
    $vendor->users()->attach($cashier->id);

    return compact('owner', 'vendor', 'cashier');
}

function receiptSale(array $data, array $overrides = []): PosSale
{
    $sale = PosSale::create(array_merge([
        'reference'       => 'POS-TESTREF1',
        'vendor_id'       => $data['vendor']->id,
        'cashier_id'      => $data['cashier']->id,
        'subtotal'        => 10000,
        'discount_amount' => 0,
        'vat_amount'      => 750,
        'total'           => 10750,
        'payment_method'  => 'cash',
        'amount_tendered' => 11000,
        'change_given'    => 250,
        'status'          => 'completed',
        'completed_at'    => now(),
    ], $overrides));

    $product = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Receipt Cat'])->id,
        'name'           => 'Itel Power Go Pro',
        'sku'            => 'ITL-PGP',
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $product->id,
        'product_name' => 'Itel Power Go Pro',
        'product_sku'  => 'ITL-PGP',
        'unit_price'   => 5000,
        'quantity'     => 2,
        'total'        => 10000,
    ]);

    return $sale;
}

test('the receipt carries the store, cashier, number, date and total', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        // Uppercasing is a CSS effect; the markup carries the real store name
        ->assertSee('Zelink Tech')
        ->assertSee('POS-TESTREF1')
        ->assertSee('Grace Cashier')
        ->assertSee('Itel Power Go Pro')
        ->assertSee('10,750.00')
        ->assertSee('Cash');
});

test('header and footer settings appear on the paper', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    VendorReceiptSetting::create([
        'vendor_id'        => $data['vendor']->id,
        'header_name'      => 'ZELINK SUPERSTORE',
        'header_address'   => '14 Aka Road, Uyo',
        'header_phone'     => '0801 234 5678',
        'footer_text'      => "Thank you for shopping\nNo refund after 7 days",
        'footer_alignment' => 'center',
    ]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee('ZELINK SUPERSTORE')
        ->assertSee('14 Aka Road, Uyo')
        ->assertSee('0801 234 5678')
        ->assertSee('No refund after 7 days');
});

test('the header name overrides the store name when set', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    VendorReceiptSetting::create([
        'vendor_id'   => $data['vendor']->id,
        'header_name' => 'ZELINK SUPERSTORE',
    ]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertSee('ZELINK SUPERSTORE')
        ->assertDontSee('Zelink Tech');
});

test('switched-off lines are left off the paper', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    VendorReceiptSetting::create([
        'vendor_id'           => $data['vendor']->id,
        'show_cashier'        => false,
        'show_receipt_number' => false,
    ]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertDontSee('Grace Cashier')
        // The printed row is gone. The reference still names the browser tab
        // (and later the saved PDF), which never reaches the paper.
        ->assertDontSee('<span>Receipt</span>', false)
        // The sale itself still prints
        ->assertSee('Itel Power Go Pro');
});

// The old modal hardcoded "VAT (7.5%)" and would have lied on any other rate.
test('the VAT line follows the vendor rate rather than a hardcoded figure', function () {
    $data = receiptVendor();
    $data['vendor']->update(['pos_vat_rate' => 5]);
    $sale = receiptSale($data, ['vat_amount' => 500]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertSee('VAT (5%)')
        ->assertDontSee('VAT (7.5%)');
});

test('VAT is omitted entirely when the store does not charge it', function () {
    $data = receiptVendor();
    $data['vendor']->update(['pos_vat_enabled' => false]);
    $sale = receiptSale($data, ['vat_amount' => 0]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertDontSee('VAT');
});

test('cash sales show tendered and change', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertSee('Tendered')
        ->assertSee('11,000.00')
        ->assertSee('Change')
        ->assertSee('250.00');
});

test('a bank transfer shows its reference', function () {
    $data = receiptVendor();
    $sale = receiptSale($data, [
        'payment_method'          => 'bank_transfer',
        'bank_transfer_reference' => 'TRF-99881',
        'amount_tendered'         => 0,
        'change_given'            => 0,
    ]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertSee('Bank Transfer')
        ->assertSee('TRF-99881');
});

// A receipt names a cashier and a customer, so it must not be readable by
// whoever guesses an id. The app turns a forbidden page load into a redirect
// rather than a dead-end 403 (see bootstrap/app.php), so what matters here is
// that the receipt is not rendered.
test('another vendor cannot open this receipt', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)->get(route('pos.receipt', $sale));

    $response->assertRedirect();
    expect($response->getContent())->not->toContain('POS-TESTREF1')
        ->and($response->getContent())->not->toContain('Grace Cashier');
});

test('a guest is sent to log in rather than shown the receipt', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    $this->get(route('pos.receipt', $sale))->assertRedirect();
});

test('the print flag adds the self-printing script, and is off by default', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertDontSee('window.print()', false);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale) . '?print=1')
        ->assertSee('window.print()', false);
});

// The commonest path of all: a store that has never opened the settings page.
// Column defaults do not apply to an unsaved model, so without defaults on the
// model itself this receipt would print with no number, date or cashier.
test('a store with no saved settings still gets a complete receipt', function () {
    $data = receiptVendor();
    $sale = receiptSale($data);

    expect(App\Models\VendorReceiptSetting::where('vendor_id', $data['vendor']->id)->exists())->toBeFalse();

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee('Zelink Tech')
        ->assertSee('POS-TESTREF1')
        ->assertSee('Grace Cashier')
        ->assertSee('Receipt')
        ->assertSee('Date');
});
