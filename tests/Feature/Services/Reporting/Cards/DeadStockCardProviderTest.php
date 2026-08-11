<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\DeadStockCardProvider;

function deadStockCardVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Dead Stock Card Store ' . uniqid()]);
    $category = Category::create(['name' => 'Dead Stock Card Category ' . uniqid()]);

    return compact('owner', 'vendor', 'category');
}

test('calm with no tied-up value when there are no dead-stock candidates', function () {
    $data = deadStockCardVendor();

    $summary = (new DeadStockCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('No dead stock candidates right now')
        ->and($summary->urgency)->toBe(CardSummary::URGENCY_CALM)
        ->and($summary->actionableCount)->toBe(0);
});

test('sums stock value across dead-stock candidates and goes to attention', function () {
    $data = deadStockCardVendor();
    // No sales, no ledger history, established, stock on hand -> dead-stock
    // candidate per ProductVelocityService's own classification.
    Product::create([
        'vendor_id' => $data['vendor']->id, 'category_id' => $data['category']->id,
        'name' => 'Stale Widget', 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 10, 'status' => 'published', 'created_at' => now()->subYear(),
    ]);

    $summary = (new DeadStockCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('₦4,000.00 tied up in 1 slow-moving/dead product')
        ->and($summary->urgency)->toBe(CardSummary::URGENCY_ATTENTION)
        ->and($summary->actionableCount)->toBe(1);
});

test('the link is null and a note flags that the detail page does not exist yet', function () {
    $data = deadStockCardVendor();

    $summary = (new DeadStockCardProvider())->summarize($data['vendor']->id);

    expect($summary->link)->toBeNull()
        ->and($summary->hasLink())->toBeFalse()
        ->and($summary->note)->not->toBeNull();
});

test('each vendor only sees its own dead stock', function () {
    $dataA = deadStockCardVendor();
    $dataB = deadStockCardVendor();
    Product::create([
        'vendor_id' => $dataA['vendor']->id, 'category_id' => $dataA['category']->id,
        'name' => 'Stale A Widget', 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 10, 'status' => 'published', 'created_at' => now()->subYear(),
    ]);

    $summaryA = (new DeadStockCardProvider())->summarize($dataA['vendor']->id);
    $summaryB = (new DeadStockCardProvider())->summarize($dataB['vendor']->id);

    expect($summaryA->actionableCount)->toBe(1)
        ->and($summaryB->actionableCount)->toBe(0);
});
