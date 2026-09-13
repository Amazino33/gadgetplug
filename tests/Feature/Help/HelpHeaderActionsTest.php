<?php

use App\Filament\Resources\HelpArticles\Pages\EditHelpArticle;
use App\Filament\Vendor\Pages\HelpCenter;
use App\Filament\Vendor\Resources\Procurements\Pages\ListProcurements;
use App\Filament\Vendor\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\HelpCenterSeeder;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function helpActionsContext(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Header Actions Store']);

    VendorRoles::seedFor($vendor);

    return compact('owner', 'vendor');
}

it('puts the tour and help buttons on the procurements page', function () {
    (new HelpCenterSeeder)->run();

    ['owner' => $owner, 'vendor' => $vendor] = helpActionsContext();

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    // Rendering is the assertion. A "Take a tour" action carries no ->url() and
    // no ->action() -- it is driven entirely by an Alpine handler in
    // extraAttributes -- and this is what proves Filament is happy to render
    // one rather than throwing on a button that does nothing server-side.
    Livewire::test(ListProcurements::class)
        ->assertOk()
        ->assertSee('Take a tour')
        ->assertSee('How do I do this?');
});

it('leaves the help button off a page whose guide is not written', function () {
    ['owner' => $owner, 'vendor' => $vendor] = helpActionsContext();

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    // Nothing seeded, so there is no "how do I add a supplier" article to point
    // at, and the button must not appear rather than link to an empty page.
    Livewire::test(ManageSuppliers::class)
        ->assertOk()
        ->assertDontSee('How do I do this?');
});

it('gives the admin a working vendor-panel preview link', function () {
    ['vendor' => $vendor] = helpActionsContext();

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $category = HelpCategory::create(['name' => 'Procurement']);
    $article = HelpArticle::create([
        'help_category_id' => $category->id,
        'title' => 'How do I record a procurement?',
        'body' => '<p>Steps.</p>',
        'is_published' => true,
    ]);

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Built from the admin panel but addressing the vendor panel, so it has to
    // carry a tenant it was never given by the current context.
    $url = HelpCenter::previewUrlFor($article);

    expect($url)
        ->toContain('/plug/'.$vendor->slug.'/help')
        ->toContain('article=how-do-i-record-a-procurement');

    Livewire::test(EditHelpArticle::class, ['record' => $article->getRouteKey()])
        ->assertOk()
        ->assertSee('View as a vendor');
});

it('hides the preview link when there is no vendor to preview as', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $category = HelpCategory::create(['name' => 'Procurement']);
    $article = HelpArticle::create([
        'help_category_id' => $category->id,
        'title' => 'A guide',
        'body' => '<p>Steps.</p>',
    ]);

    test()->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(HelpCenter::previewUrlFor($article))->toBe('');
});
