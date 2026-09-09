<?php

use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Store;
use App\Models\VendorReceiptSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function brandingSale(array $ctx, ?Store $store = null): PosSale
{
    $sale = PosSale::create([
        'reference'       => 'POS-' . strtoupper(uniqid()),
        'vendor_id'       => $ctx['vendor']->id,
        'store_id'        => ($store ?? $ctx['store'])->id,
        'cashier_id'      => $ctx['owner']->id,
        'subtotal'        => 13400,
        'vat_amount'      => 0,
        'total'           => 13400,
        'payment_method'  => 'cash',
        'amount_tendered' => 13400,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $ctx['product']->id,
        'product_name' => 'P1201P 20000MAH', 'unit_price' => 13400, 'quantity' => 1, 'total' => 13400,
    ]);

    return $sale;
}

it('names the branch when the vendor has more than one store', function () {
    $ctx    = debtTenderContext();
    $oraimo = Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Oraimo Store',
        'address' => '12 Allen Avenue, Ikeja', 'phone' => '08031234567',
    ]);

    Sanctum::actingAs($ctx['owner']);

    $sale = brandingSale($ctx, $oraimo);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertSee('Oraimo Store')
        // The branch's own address, not head office — sending someone across
        // town for a product they bought here is worse than printing nothing.
        ->assertSee('12 Allen Avenue, Ikeja')
        ->assertSee('08031234567');
});

it('says nothing about a branch when there is only one store', function () {
    $ctx = debtTenderContext();

    Sanctum::actingAs($ctx['owner']);

    // A single-store vendor gains nothing from a line the customer cannot act
    // on, and thermal paper is not free.
    $sale = brandingSale($ctx);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertDontSee('<p class="store-branch"', escape: false);
});

it('falls back to the vendor address when the branch has none', function () {
    $ctx    = debtTenderContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Itel Home']);

    VendorReceiptSetting::updateOrCreate(
        ['vendor_id' => $ctx['vendor']->id],
        ['header_address' => '5 Head Office Road', 'header_phone' => '08090001111'],
    );

    Sanctum::actingAs($ctx['owner']);

    $sale = brandingSale($ctx, $branch);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertSee('Itel Home')
        ->assertSee('5 Head Office Road');
});

it('thanks the customer even when the vendor never configured a footer', function () {
    $ctx = debtTenderContext();

    Sanctum::actingAs($ctx['owner']);

    // Receipts were ending on a bare total because every vendor wanted a
    // thank-you and none of them thought to type one.
    $sale = brandingSale($ctx);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertSee('Thank you for your patronage.');
});

it('prefers the vendor own footer text when they set one', function () {
    $ctx = debtTenderContext();

    VendorReceiptSetting::updateOrCreate(
        ['vendor_id' => $ctx['vendor']->id],
        ['footer_text' => 'Goods sold are not returnable after 7 days.'],
    );

    Sanctum::actingAs($ctx['owner']);

    $sale = brandingSale($ctx);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertSee('Goods sold are not returnable after 7 days.')
        ->assertDontSee('Thank you for your patronage.');
});

it('wastes no page margin at the top of the roll', function () {
    $ctx = debtTenderContext();

    Sanctum::actingAs($ctx['owner']);

    // The printer feeds its own leading strip; a page margin on top of that is
    // blank paper on every receipt the shop ever prints.
    $sale = brandingSale($ctx);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertSee('margin: 0 3mm 4mm', escape: false);
});

it('does not print itself, so the till decides when', function () {
    $ctx = debtTenderContext();

    Sanctum::actingAs($ctx['owner']);

    // Without ?print=1 there must be no self-printing script: two mechanisms
    // able to fire is what put one sale on paper twice.
    $sale = brandingSale($ctx);

    $this->get("/api/pos/sales/{$sale->id}/receipt")
        ->assertSuccessful()
        ->assertDontSee('window.print()', escape: false);
});
