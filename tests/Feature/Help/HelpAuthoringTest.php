<?php

use App\Filament\Resources\HelpArticles\HelpArticleResource;
use App\Filament\Resources\HelpArticles\Pages\CreateHelpArticle;
use App\Filament\Resources\HelpArticles\Pages\EditHelpArticle;
use App\Filament\Resources\HelpArticles\Pages\ListHelpArticles;
use App\Filament\Resources\HelpCategories\HelpCategoryResource;
use App\Filament\Resources\HelpCategories\Pages\ManageHelpCategories;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function actAsPlatformAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

it('renders the article editor, rich text and all', function () {
    actAsPlatformAdmin();

    // A smoke test with a point: the body field is wired to the model's
    // registered rich-content attribute and a Spatie attachment provider, and
    // a mistake in that wiring only shows up when the schema is built.
    Livewire::test(CreateHelpArticle::class)
        ->assertOk()
        ->assertSee('Write it the way you would say it over the phone');
});

it('writes an article from the admin form', function () {
    actAsPlatformAdmin();

    $category = HelpCategory::create(['name' => 'Procurement']);

    Livewire::test(CreateHelpArticle::class)
        ->fillForm([
            'title' => 'How do I record a procurement?',
            'help_category_id' => $category->id,
            'excerpt' => 'Menu, supplier, items, save.',
            'body' => '<p>1. Open the menu.</p>',
            'tour_key' => 'record-procurement',
            'is_published' => true,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = HelpArticle::firstWhere('title', 'How do I record a procurement?');

    expect($article)->not->toBeNull()
        ->and($article->slug)->toBe('how-do-i-record-a-procurement')
        ->and($article->tour_key)->toBe('record-procurement')
        ->and($article->is_published)->toBeTrue();
});

it('opens an existing article for editing', function () {
    actAsPlatformAdmin();

    $category = HelpCategory::create(['name' => 'Products']);
    $article = HelpArticle::create([
        'help_category_id' => $category->id,
        'title' => 'How do I add a product?',
        'body' => '<p>Start in the catalogue.</p>',
        'is_published' => true,
    ]);

    // HelpArticle routes by slug (same as Category), so that is the binding
    // Filament resolves the edit page with.
    Livewire::test(EditHelpArticle::class, ['record' => $article->getRouteKey()])
        ->assertOk()
        ->assertFormSet(['title' => 'How do I add a product?']);
});

it('lists articles and categories for the admin', function () {
    actAsPlatformAdmin();

    $category = HelpCategory::create(['name' => 'Orders']);
    HelpArticle::create([
        'help_category_id' => $category->id,
        'title' => 'Where are my orders?',
        'body' => '<p>Under Orders.</p>',
        'is_published' => true,
    ]);

    Livewire::test(ListHelpArticles::class)->assertOk()->assertSee('Where are my orders?');
    Livewire::test(ManageHelpCategories::class)->assertOk()->assertSee('Orders');
});

it('refuses the authoring pages to someone who is not a platform admin', function () {
    $this->actingAs(User::factory()->create());

    // Two independent gates, and this asserts both. The panel itself turns a
    // non-admin away before routing (a redirect, not a render), and the
    // resources' own canAccess() says no regardless of how they were reached.
    $this->get('/admin/help-articles')->assertRedirect();
    $this->get('/admin/help-categories')->assertRedirect();

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(HelpArticleResource::canAccess())->toBeFalse()
        ->and(HelpCategoryResource::canAccess())->toBeFalse();
});

it('generates a distinct slug when two guides share a title', function () {
    $category = HelpCategory::create(['name' => 'Duplicates']);

    $first = HelpArticle::create(['help_category_id' => $category->id, 'title' => 'Same name', 'body' => '<p>a</p>']);
    $second = HelpArticle::create(['help_category_id' => $category->id, 'title' => 'Same name', 'body' => '<p>b</p>']);

    // The slug is the deep-link a "?" button and a support reply both use, so a
    // collision would silently point one guide at the other.
    expect($first->slug)->not->toBe($second->slug);
});
