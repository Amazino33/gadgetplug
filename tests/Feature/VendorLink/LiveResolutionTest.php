<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Product;
use App\Models\SupplierLink;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/** Publish one product and hand back both sides of it. */
function published(float $price = 10000, int $stock = 5, float $markup = 40): array
{
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: $markup);
    $source = linkProduct($supplier, price: $price, stock: $stock);

    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    return [Product::linked()->firstOrFail(), $source, $link, $reseller, $supplier];
}

describe('price follows the supplier', function () {
    test('the listing quotes his price plus the markup, rounded up to 990', function () {
        [$listing] = published(price: 10000, markup: 40);

        expect((float) $listing->price)->toBe(14990.0);
    });

    test('his price change moves the shelf price with nothing to run', function () {
        [$listing, $source] = published(price: 10000, markup: 40);

        $source->update(['price' => 20000]);

        // No sync, no job, no republish — the listing simply asks him.
        expect((float) $listing->fresh()->price)->toBe(28990.0);
    });

    test('a switched-off link stops quoting him and falls back to the last price', function () {
        [$listing, $source, $link] = published(price: 10000, markup: 40);

        $link->update(['is_active' => false]);
        $source->update(['price' => 99000]);

        // Stale, but a real price somebody set — better than showing zero, and
        // it stops following a supplier the arrangement has ended with.
        expect((float) $listing->fresh()->price)->toBe(14990.0);
    });

    test('a deleted source falls back rather than pricing at nothing', function () {
        [$listing, $source] = published(price: 10000, markup: 40);

        $source->delete();

        expect((float) $listing->fresh()->price)->toBe(14990.0);
    });

    test('an ordinary product is untouched by any of this', function () {
        $product = linkProduct(linkVendor('Ordinary'), price: 7500);

        expect((float) $product->price)->toBe(7500.0);
    });
});

describe('stock follows the supplier', function () {
    test('the listing is sellable exactly while he has units', function () {
        [$listing, $source] = published(stock: 5);

        expect($listing->available_stock)->toBe(5);

        $source->update(['stock_quantity' => 0]);

        expect($listing->fresh()->available_stock)->toBe(0);
    });

    test('his own reservations are not stock this listing can sell', function () {
        [$listing, $source] = published(stock: 5);

        // Three promised to his own online orders.
        $source->update(['reserved_stock' => 3]);

        expect($listing->fresh()->available_stock)->toBe(2);
    });

    test('a switched-off link reads as out of stock, not as unlimited', function () {
        [$listing, , $link] = published(stock: 9);

        $link->update(['is_active' => false]);

        // The safe direction: refusing an order beats taking one nobody can fill.
        expect($listing->fresh()->available_stock)->toBe(0);
    });

    test('the listing still physically holds nothing', function () {
        [$listing] = published(stock: 12);

        expect((int) $listing->stock_quantity)->toBe(0)
            ->and($listing->available_stock)->toBe(12);
    });
});

describe('the storefront', function () {
    test('shows a linked listing while the supplier has stock', function () {
        [$listing, $source] = published(stock: 4);

        expect(Product::visibleOnline()->inStockForSale()->pluck('id'))->toContain($listing->id);

        $source->update(['stock_quantity' => 0]);

        // Filtered out in SQL, where the catalogue actually decides.
        expect(Product::visibleOnline()->inStockForSale()->pluck('id'))->not->toContain($listing->id);
    });

    test('keeps the supplier himself invisible while his goods sell', function () {
        [$listing, $source, , , $supplier] = published(stock: 4);

        $visible = Product::visibleOnline()->inStockForSale()->pluck('id');

        // His shop is switched off for the marketplace, so his own product is
        // absent — but the reseller's listing of it is right there.
        expect($visible)->toContain($listing->id)
            ->and($visible)->not->toContain($source->id)
            ->and($supplier->canSellOnline())->toBeFalse();
    });

    test('a switched-off link takes the listing off the shelf', function () {
        [$listing, , $link] = published(stock: 4);

        $link->update(['is_active' => false]);

        expect(Product::visibleOnline()->inStockForSale()->pluck('id'))->not->toContain($listing->id);
    });

    test('an ordinary out-of-stock product is still excluded', function () {
        $vendor = linkVendor('Ordinary');
        $out = linkProduct($vendor, stock: 0);
        $in = linkProduct($vendor, stock: 3);

        $visible = Product::visibleOnline()->inStockForSale()->pluck('id');

        expect($visible)->toContain($in->id)
            ->and($visible)->not->toContain($out->id);
    });
});

describe('the supplier is never written to', function () {
    test('publishing and resolving leave his stock and price exactly as they were', function () {
        [$listing, $source] = published(price: 10000, stock: 12);

        // Read every way the storefront would.
        $listing->fresh()->price;
        $listing->fresh()->available_stock;
        Product::visibleOnline()->inStockForSale()->get();

        expect((int) $source->fresh()->stock_quantity)->toBe(12)
            ->and((int) $source->fresh()->reserved_stock)->toBe(0)
            ->and((float) $source->fresh()->price)->toBe(10000.0);
    });

    test('his edits never reach the copied pictures and words', function () {
        [$listing, $source] = published();
        $source->update(['description' => 'His new copy', 'name' => 'His new name']);

        // Presentation was copied once and belongs to the reseller. Only money
        // stayed live.
        expect($listing->fresh()->description)->not->toBe('His new copy')
            ->and($listing->fresh()->name)->not->toBe('His new name');
    });
});
