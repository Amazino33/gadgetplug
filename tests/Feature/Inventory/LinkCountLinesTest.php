<?php

use App\Models\AuditSession;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;

// Reattaches counts taken before audit_sessions recorded which count they came
// from. The matching must be right or someone's count is filed under the wrong
// session, so the refusal cases matter most.

function linkContext(): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    $vendor->users()->attach($staff->id);

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $products = collect(range(1, 3))->map(fn (int $i) => Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => "Product {$i}",
        'slug'           => "product-{$i}",
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]));

    return compact('owner', 'staff', 'vendor', 'products');
}

function historicSession(array $c, $products, $submittedAt): BlindCountSession
{
    return BlindCountSession::create([
        'vendor_id'        => $c['vendor']->id,
        'storekeeper_a_id' => $c['staff']->id,
        'status'           => 'completed',
        'frequency'        => 'daily',
        'product_order'    => $products->pluck('id')->all(),
        'a_submitted_at'   => $submittedAt,
        'b_submitted_at'   => $submittedAt,
    ]);
}

function historicLine(array $c, Product $product, $createdAt): AuditSession
{
    $line = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $product->id,
        'storekeeper_a_id' => $c['staff']->id,
        'count_a'          => 9,
        'status'           => 'verified',
    ]);

    // created_at is not fillable; the real rows were written by the counting flow.
    $line->forceFill(['created_at' => $createdAt])->save();

    return $line->fresh();
}

it('reports without writing anything unless forced', function () {
    $c = linkContext();
    $at = now()->subDays(3);
    $session = historicSession($c, $c['products'], $at);
    historicLine($c, $c['products'][0], $at->copy()->addSeconds(5));

    $this->artisan('counts:link-lines')
        ->expectsOutputToContain('would be attached')
        ->assertSuccessful();

    expect($session->auditLines()->count())->toBe(0);
});

it('attaches lines to the count that produced them', function () {
    $c = linkContext();
    $at = now()->subDays(3);
    $session = historicSession($c, $c['products'], $at);

    foreach ($c['products'] as $product) {
        historicLine($c, $product, $at->copy()->addSeconds(5));
    }

    $this->artisan('counts:link-lines', ['--force' => true])->assertSuccessful();

    expect($session->fresh()->auditLines()->count())->toBe(3);
});

it('leaves alone a line written well outside the count window', function () {
    $c = linkContext();
    $at = now()->subDays(3);
    $session = historicSession($c, $c['products'], $at);

    // Same product and counter, but hours later — a different count.
    historicLine($c, $c['products'][0], $at->copy()->addHours(6));

    $this->artisan('counts:link-lines', ['--force' => true])->assertSuccessful();

    expect($session->fresh()->auditLines()->count())->toBe(0);
});

it('refuses a line that two counts could both claim', function () {
    $c = linkContext();
    $at = now()->subDays(3);

    // Two counts by the same person minutes apart, both covering the product.
    $first  = historicSession($c, $c['products'], $at);
    $second = historicSession($c, $c['products'], $at->copy()->addMinutes(2));

    historicLine($c, $c['products'][0], $at->copy()->addSeconds(30));

    $this->artisan('counts:link-lines', ['--force' => true])
        ->expectsOutputToContain('more than one count')
        ->assertSuccessful();

    expect($first->fresh()->auditLines()->count())->toBe(0)
        ->and($second->fresh()->auditLines()->count())->toBe(0);
});

it('never steals a line from another storekeeper', function () {
    $c = linkContext();
    $other = User::factory()->create();
    $c['vendor']->users()->attach($other->id);

    $at = now()->subDays(3);
    $session = historicSession($c, $c['products'], $at);

    $line = historicLine($c, $c['products'][0], $at->copy()->addSeconds(5));
    $line->forceFill(['storekeeper_a_id' => $other->id])->save();

    $this->artisan('counts:link-lines', ['--force' => true])->assertSuccessful();

    expect($session->fresh()->auditLines()->count())->toBe(0);
});

it('does nothing when every count is already attached', function () {
    linkContext();

    $this->artisan('counts:link-lines', ['--force' => true])
        ->expectsOutputToContain('already has its lines')
        ->assertSuccessful();
});
