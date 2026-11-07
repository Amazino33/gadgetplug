<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Models\AuditSession;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

// The Inventory Count (blind count) with halves. The count screen took 7½,
// but finishing the count cast both counters' figures to (int) — so 7½ was
// recorded as 7 and every half became a false shortage. These pin the whole
// journey: entry, comparison, the stock correction and the count line.

function halvesCountShop(bool $halves, int $participants, int|float $onShelf): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name' => ($halves ? 'Veruzo Count ' : 'Mart Count ').uniqid(),
        'pos_blind_count_participants' => $participants,
        'pos_blind_count_frequency' => 'none',
        'allow_half_quantities' => $halves,
    ]);
    VendorRoles::seedFor($vendor);

    $counters = collect(range(1, $participants))->map(function () use ($vendor) {
        $user = User::factory()->create();
        setPermissionsTeamId($vendor->id);
        $user->assignRole('storekeeper');
        $vendor->users()->syncWithoutDetaching([$user->id]);

        return $user;
    });

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Count Cat '.uniqid()])->id,
        'name' => 'Hero Lager',
        'base_unit' => 'Crate',
        'price' => 10100,
        'cost_price' => 8000,
        'stock_quantity' => 0,
        'status' => 'published',
        'published_at' => now(),
    ]);
    // Through the model, so the observer keeps products.stock_quantity in step.
    ProductStoreStock::where('product_id', $product->id)->firstOrFail()->update(['quantity' => $onShelf]);

    return compact('vendor', 'counters', 'product');
}

/** One counter starts (or joins) the session and submits $count for the product. */
function halvesCountAs(User $counter, Product $product, int|float|string $count, bool $start): void
{
    test()->actingAs($counter);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant(Vendor::find($product->vendor_id));

    $component = Livewire::test(BlindCount::class);

    // Counter A starts the session; counter B joins the one waiting for them.
    $component->call($start ? 'startSession' : 'joinAsB');

    $component->call('finishCounting', [$product->id => ['count' => $count, 'note' => null]]);
}

it('verifies a solo count of 7½ against 7½ on the shelf, and changes nothing', function () {
    $ctx = halvesCountShop(true, 1, 7.5);

    halvesCountAs($ctx['counters'][0], $ctx['product'], 7.5, start: true);

    $line = AuditSession::where('product_id', $ctx['product']->id)->sole();

    expect($line->status)->toBe('verified')
        ->and($line->count_a)->toBe(7.5)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(7.5);
});

it('flags half a crate short on a solo count, and records it as ½ — not a crate', function () {
    $ctx = halvesCountShop(true, 1, 7.5);

    halvesCountAs($ctx['counters'][0], $ctx['product'], 7, start: true);

    $line = AuditSession::where('product_id', $ctx['product']->id)->sole();

    expect($line->status)->toBe('discrepancy')
        ->and($line->count_a)->toBe(7)
        ->and($line->countedVariance())->toBe(-0.5);
});

it('corrects stock to 7½ when both counters agree on 7½', function () {
    $ctx = halvesCountShop(true, 2, 10);

    halvesCountAs($ctx['counters'][0], $ctx['product'], 7.5, start: true);
    halvesCountAs($ctx['counters'][1], $ctx['product'], 7.5, start: false);

    $line = AuditSession::where('product_id', $ctx['product']->id)->sole();

    expect($line->status)->toBe('verified')
        ->and($line->count_a)->toBe(7.5)
        ->and($line->count_b)->toBe(7.5)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(7.5);
});

it('treats 7 and 7½ from two counters as a disagreement', function () {
    $ctx = halvesCountShop(true, 2, 10);

    halvesCountAs($ctx['counters'][0], $ctx['product'], 7, start: true);
    halvesCountAs($ctx['counters'][1], $ctx['product'], 7.5, start: false);

    expect(AuditSession::where('product_id', $ctx['product']->id)->sole()->status)->toBe('discrepancy')
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(10);
});

it('still counts whole units for a shop without halves', function () {
    $ctx = halvesCountShop(false, 1, 10);

    // The screen only lets them type whole numbers; even if 7.5 arrived,
    // it is kept whole exactly as before.
    halvesCountAs($ctx['counters'][0], $ctx['product'], '7.5', start: true);

    $line = AuditSession::where('product_id', $ctx['product']->id)->sole();

    expect($line->count_a)->toBe(7)
        ->and(BlindCountSession::where('vendor_id', $ctx['vendor']->id)->sole()->status)->not->toBe('a_counting');
});
