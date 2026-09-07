<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

test('a resold listing whose supplier has stock is buyable on its product page', function () {
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);

    // The supplier has plenty.
    $source = linkProduct($supplier, price: 18500, stock: 9);
    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    $listing = Product::linked()->firstOrFail();

    // The listing holds none of its own — that is the design, not a fault.
    expect((int) $listing->stock_quantity)->toBe(0)
        ->and($listing->available_stock)->toBe(9);

    $html = $this->get(route('product.show', $listing->slug))->assertOk()->getContent();

    expect($html)->not->toContain('Out of Stock');
});

test('a resold listing goes out of stock when the supplier actually runs out', function () {
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier);

    $source = linkProduct($supplier, stock: 2);
    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    $source->update(['stock_quantity' => 0]);

    $listing = Product::linked()->firstOrFail();
    $html = $this->get(route('product.show', $listing->slug))->assertOk()->getContent();

    expect($html)->toContain('Out of Stock');
});

test('an ordinary product still reads out of stock when it is', function () {
    $vendor = linkVendor('Ordinary');
    $sold = linkProduct($vendor, stock: 0);

    $html = $this->get(route('product.show', $sold->slug))->assertOk()->getContent();

    expect($html)->toContain('Out of Stock');
});
