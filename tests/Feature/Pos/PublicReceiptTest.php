<?php

use App\Models\Category;
use App\Models\PosCustomer;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorReceiptSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pubVendor(): array
{
    $owner  = User::factory()->create(['name' => 'Owner Person']);
    $vendor = Vendor::create([
        'user_id'         => $owner->id,
        'name'            => 'Zelink Tech',
        'slug'            => 'zelink-pub',
        'pos_vat_enabled' => true,
        'pos_vat_rate'    => 7.5,
    ]);

    $cashier = User::factory()->create(['name' => 'Grace Cashier']);
    $vendor->users()->attach($cashier->id);

    return compact('owner', 'vendor', 'cashier');
}

function pubSale(array $data, array $overrides = []): PosSale
{
    $sale = PosSale::create(array_merge([
        'reference'       => 'POS-PUB' . fake()->unique()->numerify('####'),
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
        'category_id'    => Category::firstOrCreate(['name' => 'Pub Cat'])->id,
        'name'           => 'Itel Power Go Pro',
        'sku'            => 'ITL-PGP-' . fake()->unique()->numerify('###'),
        'price'          => 5000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $product->id,
        'product_name' => 'Itel Power Go Pro',
        'unit_price'   => 5000,
        'quantity'     => 2,
        'total'        => 10000,
    ]);

    return $sale->fresh();
}

test('every sale is given an unguessable token automatically', function () {
    $data = pubVendor();
    $a    = pubSale($data);
    $b    = pubSale($data);

    expect($a->public_token)->not->toBeNull()
        ->and(strlen($a->public_token))->toBe(16)
        ->and($a->public_token)->not->toBe($b->public_token);
});

test('the public page opens with no login and shows the sale', function () {
    $data = pubVendor();
    $sale = pubSale($data);

    $this->get(route('receipt.public', $sale->public_token))
        ->assertOk()
        ->assertSee('Zelink Tech')
        ->assertSee($sale->reference)
        ->assertSee('Itel Power Go Pro')
        ->assertSee('10,750.00');
});

// The paper can be photographed or left on a counter, so whoever holds it is
// not necessarily who bought. This is the property the whole design rests on.
test('the customer name and phone never reach the public page', function () {
    $data     = pubVendor();
    $customer = PosCustomer::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Ada Buyer',
        'phone'     => '08031234567',
    ]);
    $sale = pubSale($data, ['customer_id' => $customer->id]);

    $this->get(route('receipt.public', $sale->public_token))
        ->assertOk()
        ->assertDontSee('Ada Buyer')
        ->assertDontSee('08031234567')
        // The cashier is staff detail; it does not belong on the customer copy
        ->assertDontSee('Grace Cashier');
});

test('a wrong token is a 404, not a hint', function () {
    $this->get(route('receipt.public', 'totallymadeup1234'))->assertNotFound();
});

test('a voided sale is not shown as a valid receipt', function () {
    $data = pubVendor();
    $sale = pubSale($data, ['status' => 'voided']);

    $this->get(route('receipt.public', $sale->public_token))->assertNotFound();
});

test('the PDF downloads as a real pdf', function () {
    $data = pubVendor();
    $sale = pubSale($data);

    $response = $this->get(route('receipt.public.pdf', $sale->public_token));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('pdf');

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toStartWith('%PDF-')
        ->and(strlen($body))->toBeGreaterThan(1000);
});

test('the QR is printed on the paper and points at the public copy', function () {
    $data = pubVendor();
    $sale = pubSale($data);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertSee('data:image/svg+xml;base64,', false)
        ->assertSee('Scan for your receipt');
});

test('turning the QR off leaves it off the paper', function () {
    $data = pubVendor();
    $sale = pubSale($data);

    VendorReceiptSetting::create(['vendor_id' => $data['vendor']->id, 'show_qr' => false]);

    $this->actingAs($data['owner'])
        ->get(route('pos.receipt', $sale))
        ->assertOk()
        ->assertDontSee('data:image/svg+xml;base64,', false);
});

// ── Loyalty ───────────────────────────────────────────────────────────────

test('loyalty is absent unless the store switches it on', function () {
    $data = pubVendor();
    $sale = pubSale($data);

    $this->get(route('receipt.public', $sale->public_token))
        ->assertOk()
        ->assertDontSee('loyalty card');

    $this->postJson(route('receipt.public.loyalty', $sale->public_token))
        ->assertStatus(422);
});

test('marking the card reports real progress from the purchase history', function () {
    $data = pubVendor();
    VendorReceiptSetting::create([
        'vendor_id'           => $data['vendor']->id,
        'loyalty_enabled'     => true,
        'loyalty_goal'        => 5,
        'loyalty_reward_text' => 'a free phone case',
    ]);

    $customer = PosCustomer::create([
        'vendor_id'          => $data['vendor']->id,
        'name'               => 'Ada Buyer',
        'phone'              => '08031234567',
        'total_transactions' => 3,
    ]);
    $sale = pubSale($data, ['customer_id' => $customer->id]);

    $this->postJson(route('receipt.public.loyalty', $sale->public_token))
        ->assertOk()
        ->assertJson([
            'claimed'  => true,
            'visits'   => 3,
            'goal'     => 5,
            'position' => 3,
            'to_go'    => 2,
            'earned'   => false,
            'reward'   => 'a free phone case',
        ]);

    expect($sale->fresh()->loyalty_claimed_at)->not->toBeNull();
});

test('a full card is reported as earned', function () {
    $data = pubVendor();
    VendorReceiptSetting::create([
        'vendor_id'       => $data['vendor']->id,
        'loyalty_enabled' => true,
        'loyalty_goal'    => 5,
    ]);

    $customer = PosCustomer::create([
        'vendor_id'          => $data['vendor']->id,
        'name'               => 'Ada Buyer',
        'phone'              => '08031234567',
        'total_transactions' => 10,
    ]);
    $sale = pubSale($data, ['customer_id' => $customer->id]);

    $this->postJson(route('receipt.public.loyalty', $sale->public_token))
        ->assertOk()
        ->assertJson(['earned' => true, 'to_go' => 0, 'position' => 5]);
});

// Forwarding the link must not let anyone stamp the same purchase repeatedly.
test('claiming twice does not move the stamp date again', function () {
    $data = pubVendor();
    VendorReceiptSetting::create([
        'vendor_id'       => $data['vendor']->id,
        'loyalty_enabled' => true,
        'loyalty_goal'    => 5,
    ]);

    $customer = PosCustomer::create([
        'vendor_id'          => $data['vendor']->id,
        'name'               => 'Ada Buyer',
        'phone'              => '08031234567',
        'total_transactions' => 2,
    ]);
    $sale = pubSale($data, ['customer_id' => $customer->id]);

    $this->postJson(route('receipt.public.loyalty', $sale->public_token))->assertOk();
    $first = $sale->fresh()->loyalty_claimed_at;

    $this->travel(2)->minutes();
    $this->postJson(route('receipt.public.loyalty', $sale->public_token))->assertOk();

    expect($sale->fresh()->loyalty_claimed_at->timestamp)->toBe($first->timestamp);
});

test('a walk-in with no customer record is nudged rather than stamped', function () {
    $data = pubVendor();
    VendorReceiptSetting::create([
        'vendor_id'       => $data['vendor']->id,
        'loyalty_enabled' => true,
    ]);

    $sale = pubSale($data); // no customer_id

    $this->postJson(route('receipt.public.loyalty', $sale->public_token))
        ->assertOk()
        ->assertJson(['claimed' => false, 'anonymous' => true]);
});

test('the store banner and button appear when configured', function () {
    $data = pubVendor();
    VendorReceiptSetting::create([
        'vendor_id'    => $data['vendor']->id,
        'banner_image' => 'receipt-banners/promo.jpg',
        'banner_link'  => 'https://example.com/promo',
        'cta_label'    => 'Chat with us on WhatsApp',
        'cta_link'     => 'https://wa.me/2348012345678',
    ]);

    $sale = pubSale($data);

    $this->get(route('receipt.public', $sale->public_token))
        ->assertOk()
        ->assertSee('receipt-banners/promo.jpg')
        ->assertSee('Chat with us on WhatsApp')
        ->assertSee('wa.me/2348012345678', false);
});
