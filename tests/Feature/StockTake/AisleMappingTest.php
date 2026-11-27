<?php

use App\Models\AisleProduct;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Step 3: mapping products to aisles from the phone, and who may do it.
 */

function stUrl(array $ctx, string $path = ''): string
{
    return "/stock-take/{$ctx['vendor']->slug}{$path}";
}

function stMappingTask(array $ctx, User $to, array $aisles): StockTakeTask
{
    $task = StockTakeTask::create([
        'vendor_id'   => $ctx['vendor']->id,
        'store_id'    => $ctx['store']->id,
        'type'        => StockTakeTask::TYPE_MAPPING,
        'assigned_to' => $to->id,
        'due_date'    => today(),
        'created_by'  => $ctx['admin']->id,
    ]);
    $task->attachAisles(collect($aisles)->pluck('id')->all());

    return $task;
}

/** Every key anywhere in a JSON body, for proving what it does not carry. */
function stKeys(array $data): array
{
    $keys = [];
    array_walk_recursive($data, function () {});
    $walk = function ($value) use (&$walk, &$keys) {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $inner) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $walk($inner);
        }
    };
    $walk($data);

    return array_values(array_unique($keys));
}

test('scanning puts a product on the aisle, and a second scan says it is already there', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Aisle 2');
    $product = stProduct($ctx);
    $product->update(['barcode' => '6001234500011']);

    $this->actingAs($ctx['admin'])
        ->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => '6001234500011'])
        ->assertOk()
        ->assertJsonPath('status', 'added')
        ->assertJsonPath('product.id', $product->id);

    $this->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => '6001234500011'])
        ->assertJsonPath('status', 'already');

    $mapping = AisleProduct::sole();

    expect($mapping->aisle_id)->toBe($aisle->id)
        ->and($mapping->source)->toBe('mapping')
        ->and($mapping->mapped_by)->toBe($ctx['admin']->id)
        ->and($mapping->mapped_at)->not->toBeNull();
});

test('a crate\'s own barcode, or the SKU, finds the product too', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Drinks');
    $product = stProduct($ctx, 'Coke 50cl');
    $product->update(['sku' => 'COKE-50']);
    $crate = ProductUnit::create([
        'product_id' => $product->id, 'vendor_id' => $ctx['vendor']->id,
        'name' => 'Crate', 'conversion_factor' => 12, 'price' => 6000, 'is_base' => false, 'is_active' => true,
    ]);
    ProductBarcode::create(['vendor_id' => $ctx['vendor']->id, 'product_id' => $product->id, 'product_unit_id' => $crate->id, 'barcode' => '5449000000996']);

    $this->actingAs($ctx['admin'])
        ->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => '5449000000996'])
        ->assertJsonPath('status', 'added')
        ->assertJsonPath('product.scanned_unit_id', $crate->id);

    $this->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => 'COKE-50'])
        ->assertJsonPath('status', 'already');
});

test('an unknown barcode is reported, then recorded with a name and a photo', function () {
    Storage::fake('public');
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 4');

    $this->actingAs($ctx['admin'])
        ->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => '9990001112223'])
        ->assertJsonPath('status', 'unknown')
        ->assertJsonPath('code', '9990001112223');

    $this->post(stUrl($ctx, '/api/unknown-items'), [
        'aisle_id' => $aisle->id,
        'barcode'  => '9990001112223',
        'name'     => 'Golden Penny Spaghetti 500g',
        'photo'    => UploadedFile::fake()->image('pack.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $item = StockTakeUnknownItem::sole();

    expect($item->name_entered)->toBe('Golden Penny Spaghetti 500g')
        ->and($item->store_id)->toBe($ctx['store']->id)
        ->and($item->aisle_id)->toBe($aisle->id)
        ->and($item->status)->toBe('open');

    Storage::disk('public')->assertExists($item->photo_path);
});

test('a product can be added by name and taken off again', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Aisle 1');
    $product = stProduct($ctx, 'Indomie Chicken');

    $this->actingAs($ctx['admin'])
        ->getJson(stUrl($ctx, '/api/products?q=Indomie'))
        ->assertJsonPath('products.0.id', $product->id);

    $this->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/products"), ['product_id' => $product->id])
        ->assertJsonPath('status', 'added');

    $this->getJson(stUrl($ctx, "/api/aisles/{$aisle->id}"))->assertJsonCount(1, 'products');

    $this->deleteJson(stUrl($ctx, "/api/aisles/{$aisle->id}/products/{$product->id}"))->assertOk();

    expect($aisle->products()->count())->toBe(0);
});

test('no mapping response carries stock, cost or price', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Aisle 1');
    $product = stProduct($ctx, 'Peak Milk', 50);
    $product->update(['barcode' => '111222333']);

    $this->actingAs($ctx['admin']);

    $bodies = [
        $this->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/scan"), ['code' => '111222333'])->json(),
        $this->getJson(stUrl($ctx, "/api/aisles/{$aisle->id}"))->json(),
        $this->getJson(stUrl($ctx, '/api/products?q=Peak'))->json(),
        $this->getJson(stUrl($ctx, '/api/aisles'))->json(),
        $this->getJson(stUrl($ctx, '/api/home'))->json(),
    ];

    foreach ($bodies as $body) {
        expect(stKeys($body))->not->toContain('stock')
            ->not->toContain('stock_quantity')
            ->not->toContain('quantity')
            ->not->toContain('cost_price')
            ->not->toContain('price');
    }
});

test('a mapping task opens its own aisles to its assignee, and nothing else', function () {
    $ctx   = stockTakeContext();
    $mine  = stAisle($ctx, 'Aisle 3');
    $other = stAisle($ctx, 'Aisle 9');
    $item  = stProduct($ctx);
    $task  = stMappingTask($ctx, $ctx['keeperA'], [$mine]);

    $this->actingAs($ctx['keeperA']);

    $this->getJson(stUrl($ctx, '/api/home'))
        ->assertJsonPath('canMapAll', false)
        ->assertJsonPath('tasks.0.id', $task->id)
        ->assertJsonPath('tasks.0.aisles.0.id', $mine->id);

    $this->postJson(stUrl($ctx, "/api/aisles/{$mine->id}/products"), ['product_id' => $item->id, 'task_id' => $task->id])
        ->assertJsonPath('status', 'added');

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_IN_PROGRESS);

    $this->postJson(stUrl($ctx, "/api/aisles/{$other->id}/products"), ['product_id' => $item->id])->assertForbidden();
    $this->getJson(stUrl($ctx, "/api/aisles/{$other->id}"))->assertForbidden();
    $this->getJson(stUrl($ctx, '/api/aisles'))->assertForbidden();

    // Someone else holding no task gets nowhere, not even with the aisle's id.
    $this->actingAs($ctx['keeperB'])
        ->postJson(stUrl($ctx, "/api/aisles/{$mine->id}/products"), ['product_id' => $item->id])
        ->assertForbidden();
});

test('once a mapping task is done or cancelled, its aisles close to the assignee', function (string $how) {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 3');
    $item  = stProduct($ctx);
    $task  = stMappingTask($ctx, $ctx['keeperA'], [$aisle]);

    $this->actingAs($ctx['keeperA']);

    if ($how === 'finished') {
        $this->postJson(stUrl($ctx, "/api/tasks/{$task->id}/finish"))->assertOk();
        expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_SUBMITTED);
    } else {
        $task->update(['status' => StockTakeTask::STATUS_CANCELLED, 'cancelled_at' => now()]);
    }

    // With no open task and no permission, the app itself is closed to them.
    $this->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/products"), ['product_id' => $item->id])->assertForbidden();
    $this->postJson(stUrl($ctx, "/api/tasks/{$task->id}/finish"))->assertForbidden();

    expect($aisle->products()->count())->toBe(0);
})->with(['finished', 'cancelled']);

test('an aisle taken out of use cannot be mapped', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Old shelf');
    $aisle->update(['is_active' => false]);

    $this->actingAs($ctx['admin'])
        ->postJson(stUrl($ctx, "/api/aisles/{$aisle->id}/products"), ['product_id' => stProduct($ctx)->id])
        ->assertForbidden();
});

test('another vendor\'s aisles and products are out of reach', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    $theirAisle   = stAisle($other, 'Their aisle');
    $theirProduct = stProduct($other);
    $ourAisle     = stAisle($ctx, 'Our aisle');

    $this->actingAs($ctx['admin']);

    // Their aisle through our URL: not found, never theirs.
    $this->getJson(stUrl($ctx, "/api/aisles/{$theirAisle->id}"))->assertNotFound();
    $this->postJson(stUrl($ctx, "/api/aisles/{$ourAisle->id}/products"), ['product_id' => $theirProduct->id])->assertNotFound();

    // Their app at all.
    $this->getJson(stUrl($other, '/api/home'))->assertForbidden();

    expect($theirAisle->products()->count())->toBe(0);
});

test('only people with a Stock Take permission or an open task get into the app', function () {
    $ctx    = stockTakeContext();
    $member = User::factory()->create();
    $ctx['vendor']->users()->attach($member->id);
    setPermissionsTeamId($ctx['vendor']->id);
    $member->assignRole('member');

    // A refused page is redirected by the app's 403 handling; the API says 403.
    $this->actingAs($member)->get(stUrl($ctx))->assertRedirect();
    $this->actingAs($member)->getJson(stUrl($ctx, '/api/home'))->assertForbidden();

    stMappingTask($ctx, $member, [stAisle($ctx, 'Aisle 1')]);

    $this->actingAs($member)->get(stUrl($ctx))->assertOk()->assertSee('stock-take-root', false);
    $this->actingAs($ctx['keeperA'])->get(stUrl($ctx))->assertOk();
});

test('free mapping lists only the active branch\'s aisles in use', function () {
    $ctx    = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);
    stAisle($ctx, 'Aisle 1');
    stAisle($ctx, 'Aisle 2')->update(['is_active' => false]);
    stAisle($ctx, 'Lekki aisle', $branch);

    $this->actingAs($ctx['owner'])
        ->getJson(stUrl($ctx, '/api/aisles'))
        ->assertJsonCount(1, 'aisles')
        ->assertJsonPath('aisles.0.name', 'Aisle 1');
});
