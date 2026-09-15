<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A vendor with two branches, each with its own storekeeper and its own stock.
 *
 * @return array{vendor: Vendor, owner: User, phones: Store, accessories: Store, keeperPhones: User, keeperAccessories: User}
 */
function twoBranchVendor(string $frequency = 'daily'): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id'                      => $owner->id,
        'name'                         => 'Two Branch Store',
        'pos_blind_count_participants' => 1,
        'pos_blind_count_frequency'    => $frequency,
    ]);

    VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);

    $accessories = $vendor->defaultStore;
    $phones = Store::create(['vendor_id' => $vendor->id, 'name' => 'Zeelink Phones']);

    $keeperAccessories = User::factory()->create(['name' => 'Ekene']);
    $keeperPhones = User::factory()->create(['name' => 'Alexander']);

    foreach ([$keeperAccessories, $keeperPhones] as $keeper) {
        $keeper->assignRole('storekeeper');
    }

    $vendor->users()->attach([$owner->id, $keeperAccessories->id, $keeperPhones->id]);
    $accessories->users()->attach($keeperAccessories->id);
    $phones->users()->attach($keeperPhones->id);

    $category = Category::create(['name' => 'Branch Category '.uniqid()]);

    // Stock in both branches, so each has something of its own to walk.
    foreach ([$accessories, $phones] as $store) {
        collect(range(1, 2))->each(fn (int $i) => branchProduct($vendor, $category, $store, $i));
    }

    return compact('vendor', 'owner', 'phones', 'accessories', 'keeperPhones', 'keeperAccessories');
}

function branchProduct(Vendor $vendor, Category $category, Store $store, int $i): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store->id,
        'category_id'    => $category->id,
        'name'           => "{$store->name} Item {$i}",
        'sku'            => 'BR-'.$store->id."-{$i}",
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 10,
        'status'         => 'published',
        'published_at'   => now(),
    ]);
}

/** Sit a counter down at one branch, as the panel would. */
function countAt(array $ctx, User $user, Store $store): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $store);
}

function finishCountAt(array $ctx, User $user, Store $store): BlindCountSession
{
    countAt($ctx, $user, $store);

    $page = Livewire::test(BlindCount::class)->call('startSession');
    $session = BlindCountSession::find($page->get('sessionId'));

    $entries = collect($session->product_order)
        ->mapWithKeys(fn (int $id) => [$id => ['count' => 10, 'note' => null]])
        ->all();

    $page->call('finishCounting', $entries);

    return $session->fresh();
}

describe('two branches counting on the same day', function () {
    test('each branch opens its own count, not the other branch\'s', function () {
        $ctx = twoBranchVendor();

        countAt($ctx, $ctx['keeperAccessories'], $ctx['accessories']);
        $first = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        countAt($ctx, $ctx['keeperPhones'], $ctx['phones']);
        $second = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        // Before this, the second counter was handed the first branch's
        // session: the page looked up the vendor's latest open count and
        // ignored which shop the counter was standing in.
        expect($second)->not->toBe($first);

        expect(BlindCountSession::find($first)->store_id)->toBe($ctx['accessories']->id)
            ->and(BlindCountSession::find($second)->store_id)->toBe($ctx['phones']->id);
    });

    test('a counter reopening the page returns to their own branch\'s count', function () {
        $ctx = twoBranchVendor();

        countAt($ctx, $ctx['keeperAccessories'], $ctx['accessories']);
        $mine = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        countAt($ctx, $ctx['keeperPhones'], $ctx['phones']);
        Livewire::test(BlindCount::class)->call('startSession');

        // Back to the first counter, who walks away and comes back.
        countAt($ctx, $ctx['keeperAccessories'], $ctx['accessories']);

        expect(Livewire::test(BlindCount::class)->get('sessionId'))->toBe($mine);
    });

    test('each branch counts only its own shelves', function () {
        $ctx = twoBranchVendor();

        countAt($ctx, $ctx['keeperPhones'], $ctx['phones']);
        $session = BlindCountSession::find(
            Livewire::test(BlindCount::class)->call('startSession')->get('sessionId'),
        );

        $names = Product::whereIn('id', $session->product_order)->pluck('name');

        expect($names)->not->toBeEmpty()
            ->and($names->every(fn (string $n) => str_contains($n, 'Zeelink Phones')))->toBeTrue();
    });

    test('a second counter at the same branch joins the open count rather than opening a rival one', function () {
        $ctx = twoBranchVendor('none');
        $second = User::factory()->create();
        setPermissionsTeamId($ctx['vendor']->id);
        $second->assignRole('storekeeper');
        $ctx['vendor']->users()->attach($second->id);
        $ctx['accessories']->users()->attach($second->id);

        countAt($ctx, $ctx['keeperAccessories'], $ctx['accessories']);
        $first = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        countAt($ctx, $second, $ctx['accessories']);
        $again = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        // Two counts over one branch would have two people counting the same
        // stock into different records, and the last to finish would win.
        expect($again)->toBe($first)
            ->and(BlindCountSession::where('store_id', $ctx['accessories']->id)->count())->toBe(1);
    });
});

describe('the cadence follows the shelves, not the counter', function () {
    test('counting one branch does not block the same person counting another', function () {
        $ctx = twoBranchVendor('daily');
        $ekene = $ctx['keeperAccessories'];

        // Ekene is assigned to both shops, as a manager who moves between them.
        $ctx['phones']->users()->attach($ekene->id);

        finishCountAt($ctx, $ekene, $ctx['accessories']);

        // Same day, second shop. The whole point of choosing one count day.
        countAt($ctx, $ekene, $ctx['phones']);

        expect(BlindCountSession::isBlockedFor($ekene->id, $ctx['vendor'], $ctx['phones']->id))
            ->toBeFalse();

        $started = Livewire::test(BlindCount::class)->call('startSession')->get('sessionId');

        expect($started)->not->toBeNull()
            ->and(BlindCountSession::find($started)->store_id)->toBe($ctx['phones']->id);
    });

    test('but re-counting the same branch is still held back', function () {
        $ctx = twoBranchVendor('daily');
        $ekene = $ctx['keeperAccessories'];

        finishCountAt($ctx, $ekene, $ctx['accessories']);

        countAt($ctx, $ekene, $ctx['accessories']);

        // The control the cadence exists for is untouched: you cannot walk the
        // same shelves twice in a day and correct your own figure.
        expect(BlindCountSession::isBlockedFor($ekene->id, $ctx['vendor'], $ctx['accessories']->id))
            ->toBeTrue();

        Livewire::test(BlindCount::class)->call('startSession');

        expect(BlindCountSession::where('store_id', $ctx['accessories']->id)->count())->toBe(1);
    });
});
