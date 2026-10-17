<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\SendMetaConversionEventJob;
use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Catalogue\OnlineNameSuggester;
use App\Services\Feed\FeedQuery;
use App\Services\Import\ParsedRow;
use App\Services\Import\ProductImporter;
use App\Support\Import\ProductField;
use Database\Factories\ProductFactory;
use Database\Seeders\CatalogueEditorRoleSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../VendorLink/Helpers.php';

uses(RefreshDatabase::class);

// Customers see the online name ("itel 2160 Dual SIM Keypad Phone – Black");
// the store keeps its internal name ("ITEL 2160 OLD ONE") for the till,
// receipts, stock and the storekeeper's messages.

const INTERNAL = 'ITEL 2160 OLD ONE';
const ONLINE   = 'itel 2160 Dual SIM Keypad Phone – Black';

function namedVendor(): Vendor
{
    $vendor = Vendor::factory()->onlineSalesEnabled()->create();
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);
    MessageTemplateSeeder::forVendor($vendor);
    VendorNotificationSetting::forVendor($vendor)->update(['storekeeper_whatsapp' => '08099887766']);

    return $vendor;
}

function namedProduct2(?Vendor $vendor = null, ?string $online = ONLINE): Product
{
    $vendor ??= namedVendor();

    return ProductFactory::new()->withPhoto()->create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'name'           => INTERNAL,
        'online_name'    => $online,
        'brand'          => 'itel',
        'price'          => 9500,
        'stock_quantity' => 20,
        'show_in_pos'    => true,
    ]);
}

function placeNamedOrder(Product $product): Order
{
    $order = Order::create([
        'reference' => 'GP-ON-'.strtoupper(uniqid()), 'customer_name' => 'Ada Buyer',
        'customer_email' => 'ada@example.com', 'customer_phone' => '08041110000',
        'shipping_address' => 'Uyo', 'total_amount' => 9500,
        'status' => 'pending', 'payment_method' => 'pay_on_delivery',
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $product->vendor_id, 'quantity' => 1, 'unit_price' => 9500,
    ]);

    return $order;
}

function editorUser(): User
{
    setPermissionsTeamId(null);
    $user = User::factory()->create();
    $user->assignRole(CatalogueEditorRoleSeeder::ROLE);

    return $user;
}

describe('which name each audience sees', function () {
    test('the storefront shows the online name when set', function () {
        $product = namedProduct2();

        $this->get(route('home'))->assertSee(ONLINE)->assertDontSee(INTERNAL);
        $this->get(route('product.show', $product->slug))
            ->assertSee(ONLINE)
            ->assertSee('<title>'.e(ONLINE), false);
    });

    test('and the internal name when there is no online name', function () {
        $product = namedProduct2(online: null);

        expect($product->displayName())->toBe(INTERNAL);
        $this->get(route('product.show', $product->slug))->assertSee(INTERNAL);
    });

    test('the feed posts the online name', function () {
        $product = namedProduct2();

        $post = collect(app(FeedQuery::class)->page(0, null, null, null, 50)['posts'])->firstWhere('id', $product->id);

        expect($post['name'])->toBe(ONLINE);
    });

    test('the till (POS API) still gets the internal name', function () {
        $vendor = namedVendor();
        namedProduct2($vendor);
        Sanctum::actingAs(User::find($vendor->user_id));

        $payload = $this->getJson('/api/pos/products?vendor_id='.$vendor->id)->assertSuccessful()->json();

        expect($payload[0]['name'])->toBe(INTERNAL);
    });

    test('a resold listing shows its supplier\'s online name', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier);
        $source->update(['online_name' => 'Supplier Online Name']);
        ProductFactory::attachPhoto($source);
        app(\App\Actions\VendorLink\PublishLinkedListingAction::class)->execute($link, [$source->id]);

        expect(Product::linked()->firstOrFail()->displayName())->toBe('Supplier Online Name');
    });
});

describe('order lines and messages', function () {
    test('the customer message carries the online name, the storekeeper\'s the internal name', function () {
        $order = placeNamedOrder(namedProduct2());

        $order->update(['status' => 'confirmed']);

        $customer = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->sole();
        $storekeeper = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'storekeeper')->sole();

        expect($customer->body)->toContain(ONLINE)->not->toContain(INTERNAL)
            ->and($storekeeper->body)->toContain(INTERNAL)->not->toContain(ONLINE);
    });

    test('each line snapshots both names when it is created', function () {
        $order = placeNamedOrder(namedProduct2());
        $line = $order->items()->sole();

        expect($line->product_name)->toBe(INTERNAL)
            ->and($line->product_display_name)->toBe(ONLINE);
    });

    test('renaming the product later does not change what an existing order says', function () {
        $product = namedProduct2();
        $order = placeNamedOrder($product);

        $product->update(['name' => 'RENAMED SHELF', 'online_name' => 'Renamed Online']);

        $order->update(['status' => 'confirmed']);

        $customer = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'customer')->sole();
        $storekeeper = DeliveryMessage::where('order_id', $order->id)->where('recipient_type', 'storekeeper')->sole();

        expect($customer->body)->toContain(ONLINE)->not->toContain('Renamed Online')
            ->and($storekeeper->body)->toContain(INTERNAL)->not->toContain('RENAMED SHELF');

        $this->get(route('track-order', ['ref' => $order->reference]))
            ->assertSee(ONLINE)
            ->assertDontSee('Renamed Online');
    });

    test('the Purchase CAPI event names what the buyer saw, from the line snapshot', function () {
        Queue::fake();
        $order = placeNamedOrder(namedProduct2());

        $order->update(['status' => 'confirmed']);

        Queue::assertPushed(SendMetaConversionEventJob::class, function ($job) {
            $event = $job->payload()['data'][0] ?? [];

            return ($event['event_name'] ?? null) === 'Purchase'
                && ($event['custom_data']['content_name'] ?? null) === ONLINE;
        });
    });

    test('the ViewContent CAPI event carries the online name and keeps its ids', function () {
        Queue::fake();
        $product = namedProduct2();

        Volt::test('pages.product-detail', ['product' => $product]);

        Queue::assertPushed(SendMetaConversionEventJob::class, function ($job) use ($product) {
            $event = $job->payload()['data'][0] ?? [];

            return ($event['event_name'] ?? null) === 'ViewContent'
                && $event['custom_data']['content_name'] === ONLINE
                && $event['custom_data']['content_ids'] === [$product->id];
        });
    });
});

describe('search finds either name', function () {
    beforeEach(fn () => Cache::forget('storefront.search-suggestions.v2'));

    test('the search overlay, by online name and by internal model code', function () {
        namedProduct2();

        Volt::test('components.search-overlay')->set('q', 'keypad')->assertSee(ONLINE);
        Volt::test('components.search-overlay')->set('q', 'OLD ONE')->assertSee(ONLINE);
    });

    test('the catalogue and the feed, by both names', function () {
        $product = namedProduct2();

        expect(Product::query()->searchCatalogue('keypad')->pluck('id')->all())->toBe([$product->id])
            ->and(Product::query()->searchCatalogue('2160 OLD')->pluck('id')->all())->toBe([$product->id]);

        $this->get(route('home', ['search' => 'keypad']))->assertSee(ONLINE);

        $ids = collect(app(FeedQuery::class)->page(0, null, null, 'keypad', 50)['posts'])->pluck('id')->all();
        expect($ids)->toBe([$product->id]);
    });
});

describe('the catalogue editor sets it', function () {
    test('Online name & SEO writes only those two columns, logged with the editor as causer', function () {
        $editor = editorUser();
        $product = namedProduct2(online: null);
        $before = Product::findOrFail($product->id)->only(['name', 'price', 'brand', 'description', 'status']);

        $this->actingAs($editor);
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListProducts::class)
            ->callTableAction('editOnlineName', $product, data: [
                'online_name' => ONLINE,
                'meta_description' => 'Tough keypad phone with a long battery.',
            ])
            ->assertHasNoTableActionErrors();

        $fresh = Product::findOrFail($product->id);

        expect($fresh->online_name)->toBe(ONLINE)
            ->and($fresh->meta_description)->toBe('Tough keypad phone with a long battery.')
            ->and($fresh->only(['name', 'price', 'brand', 'description', 'status']))->toBe($before);

        $log = Activity::where('subject_type', $fresh->getMorphClass())->where('subject_id', $fresh->id)->where('event', 'updated')->latest('id')->first();
        expect($log->causer_id)->toBe($editor->id)
            ->and($log->properties['attributes']['online_name'])->toBe(ONLINE);
    });

    test('Suggest name fills the field and never saves on its own', function () {
        $editor = editorUser();
        $product = namedProduct2(online: null);

        $this->actingAs($editor);
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(ListProducts::class)
            ->mountAction(TestAction::make('editOnlineName')->table($product))
            ->callAction(TestAction::make('suggestName')->schemaComponent('online_name', schema: 'mountedActionSchema0'));

        // The modal is still open with the draft in the field…
        expect($component->get('mountedActions.0.data.online_name'))->toContain('2160')
            ->and(Product::findOrFail($product->id)->online_name)->toBeNull();
    });

    test('the suggester turns shelf shorthand into something people search for', function () {
        $product = new Product(['name' => INTERNAL, 'brand' => 'itel', 'specifications' => ['Colour' => 'black', 'SIM' => 'Dual SIM', 'Frame' => 'Metal']]);
        $product->setRelation('category', new Category(['name' => 'Feature Phones']));

        expect(app(OnlineNameSuggester::class)->suggest($product))
            ->toBe('itel 2160 Dual SIM Feature Phone – Black');
    });

    test('a vendor cannot set it — not via the policy, not via the panel form, not via import', function () {
        $product = namedProduct2(online: null);
        $owner = User::find($product->vendor->user_id);

        expect(\Illuminate\Support\Facades\Gate::forUser($owner)->allows('updateOnlineName', $product))->toBeFalse();

        $this->actingAs($owner);
        $row = new ParsedRow(2, [ProductField::Name->value => INTERNAL, ProductField::OnlineName->value => 'Vendor Sneaked Name'], matchedProductId: $product->id);

        app(ProductImporter::class)->applyChunk(collect([$row]), $product->vendor);

        expect(Product::findOrFail($product->id)->online_name)->toBeNull();
    });

    test('an import by someone allowed to edit online names does write it, and export round-trips it', function () {
        $product = namedProduct2(online: null);
        $this->actingAs(editorUser());

        $row = new ParsedRow(2, [ProductField::Name->value => INTERNAL, ProductField::OnlineName->value => ONLINE], matchedProductId: $product->id);

        app(ProductImporter::class)->applyChunk(collect([$row]), $product->vendor);

        expect(Product::findOrFail($product->id)->online_name)->toBe(ONLINE)
            ->and(collect(ProductField::exportColumns())->map->value->all())
                ->toContain('online_name', 'meta_description');
    });
});

describe('panels', function () {
    test('a resold listing row in All Products shows its supplier\'s photo', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier);
        ProductFactory::attachPhoto($source);
        app(\App\Actions\VendorLink\PublishLinkedListingAction::class)->execute($link, [$source->id]);
        $listing = Product::linked()->firstOrFail();

        $this->actingAs(editorUser());
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('thumb', $source->getFirstMediaUrl('product-images', 'thumb'), $listing)
            ->assertSee('Images managed on supplier product');
    });

    test('the vendor sees the online name read-only and saving their form leaves it alone', function () {
        (new \Database\Seeders\VendorPermissionsSeeder)->run();
        $product = namedProduct2();
        $owner = User::find($product->vendor->user_id);

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        Filament::setTenant($product->vendor);

        Livewire::test(\App\Filament\Vendor\Resources\Products\Pages\EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Set by GadgetPlug for online search')
            ->assertSee(ONLINE)
            ->assertFormFieldDoesNotExist('online_name')
            ->set('data.online_name', 'Vendor Tried This')
            ->call('save');

        expect(Product::findOrFail($product->id)->online_name)->toBe(ONLINE);
    });
});
