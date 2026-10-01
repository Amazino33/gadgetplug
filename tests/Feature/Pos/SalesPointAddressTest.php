<?php

// The till moved from /pos to /sales-point in October 2026: in Nigeria "POS"
// is the card machine, so the software was renamed Sales Point. The old
// address has to keep working — till icons installed before the move point
// at it.

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salesPointVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Zeelink Tech '.uniqid(),
    ]);
}

test('the till opens at its new address, under its new name', function () {
    $vendor = salesPointVendor();

    $this->get("/sales-point/{$vendor->slug}")
        ->assertOk()
        ->assertSee("{$vendor->name} — Sales Point", false)
        ->assertDontSee('— POS', false);
});

test('the old till address sends the till to the new one', function () {
    $vendor = salesPointVendor();

    $this->get("/pos/{$vendor->slug}")
        ->assertStatus(301)
        ->assertRedirect("/sales-point/{$vendor->slug}");
});

test('the old bare address and its query string are carried across', function () {
    $this->get('/pos')->assertStatus(301)->assertRedirect('/sales-point');
    $this->get('/pos/zeelink-tech?from=icon')->assertRedirect('/sales-point/zeelink-tech?from=icon');
});

test('the till API is not caught by the redirect', function () {
    // /api/pos is the till's own back end, not an address anyone visits.
    $this->postJson('/api/pos/sessions/open')->assertUnauthorized();
});

test('receipts are printed from the new address', function () {
    expect(route('pos.receipt', 1, false))->toBe('/sales-point/receipt/1');
});
