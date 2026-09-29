<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function directoryVendor(array $over = []): Vendor
{
    return Vendor::create(array_merge([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Directory Shop '.uniqid(),
        'online_sales_enabled' => true,
    ], $over));
}

function directoryProduct(Vendor $vendor, array $over = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Directory Cat'])->id,
        'name'           => 'Directory Item '.Str::random(6),
        'price'          => 12000,
        'stock_quantity' => 4,
        'status'         => 'published',
        'published_at'   => now(),
        'show_online'    => true,
    ], $over));
}

test('the home hero links to the store directory', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee(route('stores.index'), false);
});

test('it lists stores that sell online and links to each store page', function () {
    $vendor = directoryVendor();
    directoryProduct($vendor);

    $this->get(route('stores.index'))
        ->assertOk()
        ->assertSee($vendor->name)
        ->assertSee(route('store.show', $vendor), false);
});

test('it leaves off stores a customer cannot buy from', function () {
    $offline = directoryVendor(['online_sales_enabled' => false]);
    directoryProduct($offline);

    $empty = directoryVendor();

    $soldOut = directoryVendor();
    directoryProduct($soldOut, ['stock_quantity' => 0]);

    $this->get(route('stores.index'))
        ->assertOk()
        ->assertDontSee($offline->name)
        ->assertDontSee($empty->name)
        ->assertDontSee($soldOut->name);
});

test('verified stores are listed first', function () {
    $plain = directoryVendor(['name' => 'Aaa Plain Shop']);
    directoryProduct($plain);
    directoryProduct($plain);

    $verified = directoryVendor(['name' => 'Zzz Verified Shop', 'is_verified' => true]);
    directoryProduct($verified);

    $this->get(route('stores.index'))
        ->assertOk()
        ->assertSeeInOrder([$verified->name, $plain->name]);
});
