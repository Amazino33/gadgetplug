<?php

use App\Filament\Resources\HelpArticles\HelpArticleResource;
use App\Filament\Resources\HelpCategories\HelpCategoryResource;
use App\Filament\Vendor\Pages\HelpCenter;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tours\TourRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Two stores and a platform admin. Help content belongs to none of them, which
 * is the whole point of most of what is asserted below.
 */
function helpContext(): array
{
    $ownerA = User::factory()->create();
    $vendorA = Vendor::create(['user_id' => $ownerA->id, 'name' => 'Store A']);

    $ownerB = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $ownerB->id, 'name' => 'Store B']);

    $admin = User::factory()->create();
    $admin->assignRole(
        Role::findOrCreate('super_admin', 'web')
    );

    $category = HelpCategory::create(['name' => 'Procurement', 'sort_order' => 1]);

    return compact('ownerA', 'vendorA', 'ownerB', 'vendorB', 'admin', 'category');
}

function publishArticle(HelpCategory $category, string $title, array $attributes = []): HelpArticle
{
    return HelpArticle::create(array_merge([
        'help_category_id' => $category->id,
        'title' => $title,
        'body' => '<p>Open the menu, then tap Procurement.</p>',
        'excerpt' => 'A short summary.',
        'is_published' => true,
        'published_at' => now()->subDay(),
    ], $attributes));
}

function actAsVendor(User $user, Vendor $vendor): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

// ── Publishing ──────────────────────────────────────────────────────────────

it('shows a published article to a vendor', function () {
    $ctx = helpContext();
    $article = publishArticle($ctx['category'], 'How do I record a procurement?');

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::withQueryParams(['article' => $article->slug])->test(HelpCenter::class)
        ->assertSee('How do I record a procurement?')
        ->assertSee('Open the menu, then tap Procurement.', escape: false);
});

it('hides a draft from a vendor even when the slug is known', function () {
    $ctx = helpContext();
    $draft = publishArticle($ctx['category'], 'Secret unfinished guide', [
        'is_published' => false,
        'published_at' => null,
    ]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::withQueryParams(['article' => $draft->slug])->test(HelpCenter::class)
        ->assertDontSee('Secret unfinished guide');
});

it('hides an article whose publish time has not arrived', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Scheduled guide', [
        'published_at' => now()->addWeek(),
    ]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::withQueryParams(['category' => $ctx['category']->slug])->test(HelpCenter::class)
        ->assertDontSee('Scheduled guide');
});

it('leaves a category off the landing page when it has nothing readable in it', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Draft only', ['is_published' => false]);

    HelpCategory::create(['name' => 'Payments', 'sort_order' => 2]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::test(HelpCenter::class)
        ->assertDontSee('Procurement')
        ->assertDontSee('Payments');
});

// ── Tenant safety ───────────────────────────────────────────────────────────

it('shows the same shared article to a completely different vendor', function () {
    $ctx = helpContext();
    $article = publishArticle($ctx['category'], 'Shared platform guide');

    // Written by nobody in particular, read by both stores. If a tenant scope
    // ever creeps onto these tables, this is the test that fails.
    foreach ([['ownerA', 'vendorA'], ['ownerB', 'vendorB']] as [$userKey, $vendorKey]) {
        actAsVendor($ctx[$userKey], $ctx[$vendorKey]);

        Livewire::withQueryParams(['article' => $article->slug])->test(HelpCenter::class)
            ->assertSee('Shared platform guide');
    }
});

it('keeps the authoring resources away from vendors', function () {
    $ctx = helpContext();

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    expect(HelpArticleResource::canAccess())->toBeFalse()
        ->and(HelpCategoryResource::canAccess())->toBeFalse();
});

it('lets the platform admin reach the authoring resources', function () {
    $ctx = helpContext();

    $this->actingAs($ctx['admin']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(HelpArticleResource::canAccess())->toBeTrue()
        ->and(HelpCategoryResource::canAccess())->toBeTrue();
});

it('opens the help centre to every vendor without a permission check', function () {
    $ctx = helpContext();

    // A member with no roles at all: help is the one page nobody should be
    // locked out of, because being lost is not a privilege level.
    $member = User::factory()->create();
    $ctx['vendorA']->users()->attach($member->id);

    actAsVendor($member, $ctx['vendorA']);

    expect(HelpCenter::canAccess())->toBeTrue();
});

// ── Search ──────────────────────────────────────────────────────────────────

it('finds an article by a word in its title', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Recording a procurement');

    // A different body on purpose: the shared fixture body mentions
    // procurement, and a search that matched it would be right to.
    publishArticle($ctx['category'], 'Adding a product', [
        'body' => '<p>Give it a name and a price.</p>',
        'excerpt' => 'Putting an item in the catalogue.',
    ]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::test(HelpCenter::class)
        ->set('search', 'procurement')
        ->assertSee('Recording a procurement')
        ->assertDontSee('Adding a product');
});

it('finds an article by a word only in its body', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Receiving a delivery', [
        'body' => '<p>Check the waybill against what actually arrived.</p>',
        'excerpt' => null,
    ]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::test(HelpCenter::class)
        ->set('search', 'waybill')
        ->assertSee('Receiving a delivery');
});

it('never returns a draft from a search', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Draft procurement guide', ['is_published' => false]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    Livewire::test(HelpCenter::class)
        ->set('search', 'procurement')
        ->assertDontSee('Draft procurement guide');
});

it('returns nothing rather than everything for a blank search', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'Some guide');

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    // A blank box means "I have not searched yet", and must land on the
    // category grid rather than dumping every article on the page.
    $component = Livewire::test(HelpCenter::class)->set('search', '   ');

    expect($component->instance()->isSearching())->toBeFalse();
});

// ── Contextual deep links ───────────────────────────────────────────────────

it('hides the contextual help button until the guide has been written', function () {
    $ctx = helpContext();

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    expect(HelpCenter::articleExists('how-do-i-record-a-procurement'))->toBeFalse();

    publishArticle($ctx['category'], 'How do I record a procurement?');

    // The per-request cache has to be defeated the way a new request would.
    (new ReflectionClass(HelpCenter::class))->setStaticPropertyValue('existsCache', []);

    expect(HelpCenter::articleExists('how-do-i-record-a-procurement'))->toBeTrue();
});

it('points the contextual link at the article inside the tenant panel', function () {
    $ctx = helpContext();
    publishArticle($ctx['category'], 'How do I add a product?');

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    $url = HelpCenter::articleUrl('how-do-i-add-a-product');

    expect($url)
        ->toContain('/plug/'.$ctx['vendorA']->slug.'/help')
        ->toContain('article=how-do-i-add-a-product');
});

// ── The article/tour link ───────────────────────────────────────────────────

it('offers the matching tour on an article that names one', function () {
    $ctx = helpContext();
    $article = publishArticle($ctx['category'], 'How do I record a procurement?', [
        'tour_key' => 'record-procurement',
    ]);

    actAsVendor($ctx['ownerA'], $ctx['vendorA']);

    $tour = Livewire::withQueryParams(['article' => $article->slug])->test(HelpCenter::class)
        ->instance()
        ->getArticleTour();

    expect($tour)->not->toBeNull()
        ->and($tour['key'])->toBe('record-procurement')
        // The vendor slug has to be baked in, or "Start" from another page
        // would navigate to a URL with a literal {vendor} in it.
        ->and($tour['start_path'])->toBe('/plug/'.$ctx['vendorA']->slug);
});

it('only accepts a tour key that actually exists', function () {
    expect(TourRegistry::has('record-procurement'))->toBeTrue()
        ->and(TourRegistry::has('not-a-real-tour'))->toBeFalse()
        ->and(TourRegistry::keys())->toContain('record-procurement', 'add-product', 'daily-report');
});

// ── Rendering ───────────────────────────────────────────────────────────────

it('marks every image in an article body as lazily loaded', function () {
    $ctx = helpContext();

    $article = publishArticle($ctx['category'], 'Guide with a screenshot', [
        'body' => '<p>Step one.</p><img src="/storage/help/one.png" alt="one">'
            .'<p>Step two.</p><img loading="eager" src="/storage/help/two.gif" alt="two">',
    ]);

    $html = $article->renderedBody();

    // The first picks up the attribute; the second already had an explicit one
    // and is left exactly as the author wrote it.
    expect($html)->toContain('loading="lazy"')
        ->and(substr_count($html, 'loading='))->toBe(2);
});
