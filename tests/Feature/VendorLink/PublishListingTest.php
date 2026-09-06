<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Filament\Vendor\Pages\VendorLinkPage;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\SupplierLink;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

function publishPanel(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

describe('publishing a supplier product', function () {
    test('creates a listing in the reseller catalogue at the marked-up price', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier, markup: 40);
        $source = linkProduct($supplier, price: 10000, stock: 7);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        $listing = Product::linked()->firstOrFail();

        // 10,000 + 40% = 14,000, rounded up to the next 990.
        expect((float) $listing->price)->toBe(14990.0)
            ->and($listing->vendor_id)->toBe($reseller->id)
            ->and($listing->source_product_id)->toBe($source->id)
            ->and($listing->supplier_link_id)->toBe($link->id)
            ->and($listing->category_id)->toBe($source->category_id)
            ->and((float) $listing->cost_price)->toBe(10000.0);
    });

    test('the listing holds no stock of its own', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler');
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier, stock: 12);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        $listing = Product::linked()->firstOrFail();

        // Mirroring his 12 units here would put stock into the reseller's
        // inventory and till that nobody can hand over — and the mirror
        // observer would overwrite it on the next stock event anyway.
        expect((int) $listing->stock_quantity)->toBe(0)
            ->and((int) ProductStoreStock::where('product_id', $listing->id)->sum('quantity'))->toBe(0)
            // The supplier's own stock is untouched by publishing.
            ->and((int) $source->fresh()->stock_quantity)->toBe(12);
    });

    test('never reaches a till, because there is nothing behind it to hand over', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $source = linkProduct($link->supplier);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        $listing = Product::linked()->firstOrFail();

        expect((bool) $listing->show_in_pos)->toBeFalse()
            ->and((bool) $listing->show_online)->toBeTrue();
    });

    test('copies the description, which then belongs to the reseller', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $source = linkProduct($link->supplier);
        $source->update(['description' => 'Original supplier copy']);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        $listing = Product::linked()->firstOrFail();

        expect($listing->description)->toBe('Original supplier copy');

        // The supplier rewriting his own words must not rewrite a listing
        // somebody is already selling.
        $source->update(['description' => 'He changed his mind']);

        expect($listing->fresh()->description)->toBe('Original supplier copy');
    });

    test('publishes many at once', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $ids = collect(range(1, 5))->map(fn () => linkProduct($link->supplier)->id)->all();

        $result = app(PublishLinkedListingAction::class)->execute($link, $ids);

        expect($result['published'])->toBe(5)
            ->and(Product::linked()->count())->toBe(5);
    });
});

describe('publishing again', function () {
    test('updates the listing instead of duplicating it', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'), markup: 40);
        $source = linkProduct($link->supplier, price: 10000);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);
        $result = app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        expect($result['updated'])->toBe(1)
            ->and($result['published'])->toBe(0)
            ->and(Product::linked()->count())->toBe(1);
    });

    test('does not undo the reseller edits to name, words or pictures', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $source = linkProduct($link->supplier);
        $source->update(['description' => 'Supplier copy']);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        $listing = Product::linked()->firstOrFail();
        $listing->update(['name' => 'My Own Title', 'description' => 'My own words']);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        // Re-publishing refreshes the money, never the shop window somebody
        // has already dressed.
        expect($listing->fresh()->name)->toBe('My Own Title')
            ->and($listing->fresh()->description)->toBe('My own words');
    });

    test('picks up a supplier price change on the mirrored price', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'), markup: 40);
        $source = linkProduct($link->supplier, price: 10000);

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);
        expect((float) Product::linked()->first()->price)->toBe(14990.0);

        $source->update(['price' => 20000]);
        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        expect((float) Product::linked()->first()->price)->toBe(28990.0)
            ->and((float) Product::linked()->first()->cost_price)->toBe(20000.0);
    });
});

describe('what publishing refuses', function () {
    test('an inactive link publishes nothing', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $source = linkProduct($link->supplier);
        $link->update(['is_active' => false]);

        expect(fn () => app(PublishLinkedListingAction::class)->execute($link->fresh(), [$source->id]))
            ->toThrow(RuntimeException::class, 'not active');

        expect(Product::linked()->count())->toBe(0);
    });

    test('a product outside the linked supplier catalogue is skipped, not published', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $stranger = linkProduct(linkVendor('Somebody Else'));

        $result = app(PublishLinkedListingAction::class)->execute($link, [$stranger->id]);

        // The link is the gate. An id that is not in the linked catalogue buys
        // nothing, however it was posted.
        expect($result['skipped'])->toBe(1)
            ->and($result['published'])->toBe(0)
            ->and(Product::linked()->count())->toBe(0);
    });

    test('one missing product does not fail the whole batch', function () {
        $link = makeLink(linkVendor('Reseller'), linkVendor('Wholesaler'));
        $good = linkProduct($link->supplier);

        $result = app(PublishLinkedListingAction::class)->execute($link, [$good->id, 999999]);

        expect($result['published'])->toBe(1)
            ->and($result['skipped'])->toBe(1);
    });
});

describe('the VendorLink page', function () {
    test('shows the linked supplier catalogue with his price and your price', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        makeLink($reseller, $supplier, markup: 40);
        linkProduct($supplier, price: 10000);

        $source = Product::where('vendor_id', $supplier->id)->firstOrFail();

        publishPanel($reseller, User::find($reseller->user_id));

        $page = Livewire::test(VendorLinkPage::class)
            ->assertOk()
            // The table defers loading, so it has to be asked for before its
            // rows can be asserted on.
            ->loadTable()
            // His catalogue, read live across the tenant line the link opens.
            ->assertCanSeeTableRecords([$source]);

        // And priced for this shop: 10,000 + 40%, rounded up to the next 990.
        expect($page->instance()->retailFor($source))->toBe(14990.0);
    });

    test('a vendor sees only catalogues linked to them', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        makeLink($reseller, $supplier);
        linkProduct($supplier, price: 10000);

        // Another vendor entirely, linked to nobody.
        $outsider = linkVendor('Outsider');

        publishPanel($outsider, User::find($outsider->user_id));

        $page = Livewire::test(VendorLinkPage::class)->assertOk();

        // No link, so the catalogue query returns nothing at all rather than
        // falling back to everything.
        expect($page->instance()->availableLinks())->toBeEmpty()
            ->and($page->instance()->currentLink())->toBeNull();
    });

    test('a hand-edited link id cannot reach another vendor catalogue', function () {
        $reseller = linkVendor('Reseller');
        makeLink($reseller, linkVendor('Wholesaler'));

        $outsider = linkVendor('Outsider');
        $othersLink = makeLink($outsider, linkVendor('Their Wholesaler'));

        publishPanel($reseller, User::find($reseller->user_id));

        $page = Livewire::test(VendorLinkPage::class)->set('supplierLinkId', $othersLink->id);

        // Re-checked against the tenant on every read, not trusted from the
        // property the browser controls.
        expect($page->instance()->currentLink())->toBeNull();
    });

    test('only the reseller owner may open it', function () {
        $reseller = linkVendor('Reseller');
        makeLink($reseller, linkVendor('Wholesaler'));

        $member = User::factory()->create();
        $reseller->users()->syncWithoutDetaching([$member->id]);

        publishPanel($reseller, User::find($reseller->user_id));
        expect(VendorLinkPage::canAccess())->toBeTrue();

        // A team member, even one on the vendor, is not the owner.
        publishPanel($reseller, $member);
        expect(VendorLinkPage::canAccess())->toBeFalse();
    });

    test('stays out of the navigation for a vendor with no supplier', function () {
        $vendor = linkVendor('Unlinked');
        // Built before the panel is booted: creating a vendor once Filament has
        // a tenant set trips its own tenancy scoping, which is not what this is
        // about.
        $wholesaler = linkVendor('Wholesaler');

        publishPanel($vendor, User::find($vendor->user_id));

        expect(VendorLinkPage::shouldRegisterNavigation())->toBeFalse();

        makeLink($vendor, $wholesaler);

        expect(VendorLinkPage::shouldRegisterNavigation())->toBeTrue();
    });
});

describe('publishing from inside the panel', function () {
    test('works with the vendor panel booted, not only from a bare action call', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier, markup: 40);
        $source = linkProduct($supplier, price: 10000);

        // The panel registers a tenancy global scope on Product. A supplier
        // read that does not drop it comes back EMPTY, and empty means "nothing
        // to publish" rather than an error — a failure shaped like success.
        // Calling the action outside the panel, as the tests above do, never
        // meets that scope, so this is the case that matters.
        publishPanel($reseller, User::find($reseller->user_id));

        $result = app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        expect($result['published'])->toBe(1)
            ->and($result['skipped'])->toBe(0)
            ->and(Product::withoutGlobalScopes()->linked()->count())->toBe(1);
    });

    test('the supplier catalogue is readable through the gateway with the panel booted', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier);
        linkProduct($supplier);
        linkProduct($supplier);

        publishPanel($reseller, User::find($reseller->user_id));

        expect(App\Services\VendorLink\SupplierCatalogue::query($link)->count())->toBe(2)
            // And a plain query still is not, so the drop is narrow rather than
            // a hole left open for everything.
            ->and(Product::where('vendor_id', $supplier->id)->count())->toBe(0);
    });
});
